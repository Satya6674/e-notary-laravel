<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\NotaryRequestNotification;
use Illuminate\Support\Facades\DB;

/**
 * Assigns an application to a Notary, or moves it to another one.
 *
 * Everything is decided on locked rows: the application first, then the Notary, the order
 * every caller takes. Two administrators acting at once therefore queue up, and the second
 * one sees the first one's result. A page that showed an older state is refused, so the
 * same application is never assigned twice by people who each thought it was free.
 *
 * Open appointments follow the application, so the Notary responsible can see and confirm
 * them. Nothing is closed, reassigned elsewhere or deleted here.
 */
class NotaryAssignmentService
{
    public const ASSIGNED = 'assigned';

    public const UNCHANGED = 'unchanged';

    public const REFUSED = 'refused';

    /** Why the last call was refused or left things as they were. */
    public ?string $message = null;

    /** Which form field the refusal belongs to, if any. */
    public ?string $field = null;

    /**
     * @param  bool  $checkSeen  whether the caller said which Notary it saw on the page
     * @param  int|null  $seenNotaryId  the Notary the page showed, or null for none
     */
    public function assign(User $admin, int $applicationId, int $notaryId, bool $checkSeen = false, ?int $seenNotaryId = null): string
    {
        $this->message = null;
        $this->field = null;

        $outcome = DB::transaction(function () use ($applicationId, $notaryId, $checkSeen, $seenNotaryId) {
            $application = NotaryRequest::whereKey($applicationId)->lockForUpdate()->first();
            $notary = User::whereKey($notaryId)->lockForUpdate()->first();

            if (! $application) {
                return $this->refuse('This application no longer exists.');
            }

            // Re-checked here, on the locked row: the page may be minutes old.
            if (! $notary || ! $notary->isApprovedNotary()) {
                return $this->refuse('Choose an approved notary.', 'notary_id');
            }

            // Nobody reviews their own application, even after a role change.
            if ($notary->id === (int) $application->user_id) {
                return $this->refuse('A notary cannot review their own application.', 'notary_id');
            }

            if (! in_array($application->status, [NotaryRequest::STATUS_PENDING, NotaryRequest::STATUS_ASSIGNED], true)) {
                return $this->refuse('Only pending or assigned requests can be assigned.');
            }

            if ($application->status === NotaryRequest::STATUS_ASSIGNED && (int) $application->notary_id === $notary->id) {
                $this->message = 'This request is already assigned to that notary.';

                return [self::UNCHANGED];
            }

            $current = $application->notary_id === null ? null : (int) $application->notary_id;

            if ($checkSeen && $current !== $seenNotaryId) {
                return $this->refuse('This application was assigned by someone else while you were viewing it. Please check it again.');
            }

            if ($application->status === NotaryRequest::STATUS_PENDING) {
                $application->transitionTo(NotaryRequest::STATUS_ASSIGNED, ['notary_id' => $notary->id]);
            } else {
                $application->update(['notary_id' => $notary->id]);
                AuditLog::record('request.reassigned', $application, ['from_notary_id' => $current, 'notary_id' => $notary->id]);
            }

            $this->moveOpenAppointments($application, $notary);

            return [self::ASSIGNED, $application, $notary, $current];
        }, 3);

        if ($outcome[0] !== self::ASSIGNED) {
            return $outcome[0];
        }

        [, $application, $notary, $previous] = $outcome;

        // After the commit, so no notice describes a change that was rolled back.
        if ($previous === null) {
            app(StaffNotifier::class)->notaryAssigned($application);
        } else {
            app(StaffNotifier::class)->notaryRemoved($application, User::find($previous));
        }

        $notary->notify(new NotaryRequestNotification($application, 'assigned'));

        return self::ASSIGNED;
    }

    /**
     * Requested and confirmed appointments go to the Notary now responsible. Completed and
     * cancelled ones keep the Notary who handled them, as history.
     */
    private function moveOpenAppointments(NotaryRequest $application, User $notary): void
    {
        $open = Appointment::where('request_id', $application->id)->whereIn('status', ['requested', 'confirmed'])
            ->where(fn ($query) => $query->whereNull('notary_id')->orWhere('notary_id', '!=', $notary->id))
            ->lockForUpdate()->get();

        foreach ($open as $appointment) {
            $from = $appointment->notary_id === null ? null : (int) $appointment->notary_id;
            $appointment->update(['notary_id' => $notary->id]);

            AuditLog::record('appointment.notary_changed', $appointment, ['application_id' => $application->id, 'from_notary_id' => $from, 'notary_id' => $notary->id]);
        }
    }

    private function refuse(string $message, ?string $field = null): array
    {
        $this->message = $message;
        $this->field = $field;

        return [self::REFUSED];
    }
}
