<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\NotaryRequestNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single place where a decision on an application is made.
 *
 *   Notary: only on applications assigned to them, along the normal lifecycle.
 *   Admin:  on any application, including changing an earlier decision.
 *
 * A reason is mandatory for both. The application row is locked, so two
 * simultaneous submissions cannot both apply.
 */
class ApplicationDecisionService
{
    public const DECISIONS = [
        'approve'  => NotaryRequest::STATUS_APPROVED,
        'reject'   => NotaryRequest::STATUS_REJECTED,
        'correct'  => NotaryRequest::STATUS_CORRECTION,
        'complete' => NotaryRequest::STATUS_COMPLETED,
    ];

    /** Why the last decide() was refused, when the reason is more than an illegal move. */
    public ?string $refusal = null;

    /**
     * @return bool false when the move is not allowed from the current status
     *
     * @throws AuthorizationException when the actor may not decide this application
     */
    public function decide(User $actor, NotaryRequest $application, string $decision, string $reason): bool
    {
        $this->refusal = null;
        $status = self::DECISIONS[$decision] ?? throw new InvalidArgumentException("Unknown decision [{$decision}]");
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        Gate::forUser($actor)->authorize('decide', $application);

        return DB::transaction(function () use ($actor, $application, $status, $reason, $decision) {
            $current = NotaryRequest::lockForUpdate()->findOrFail($application->id);

            // The check above saw the caller's copy. An Admin may have reassigned the application,
            // or ended this Notary's approval, before the lock: check again against what is true now.
            if (! ($now = $actor->fresh()) || ! Gate::forUser($now)->allows('decide', $current)) {
                $this->refusal = 'This application is no longer assigned to you. Nothing was changed.';

                return false;
            }

            if (! $this->allowed($actor, $current, $status)) {
                return false;
            }

            if ($status === NotaryRequest::STATUS_COMPLETED && ($this->refusal = $this->certificateBlocker($current))) {
                return false;
            }

            $override = $actor->isAdmin() && in_array($current->status, NotaryRequest::DECIDED_STATUSES, true);

            $current->forceStatus($status, [
                'decided_by'       => $actor->id,
                'decision_remarks' => $reason,
                'rejection_reason' => $status === NotaryRequest::STATUS_REJECTED ? $reason : null,
            ], $reason, $override);

            if ($status === NotaryRequest::STATUS_COMPLETED) {
                $this->issueDemoCertificates($current);
            }

            $current->user?->notify(new NotaryRequestNotification($current, $this->event($status)));
            app(StaffNotifier::class)->statusChanged($current, $actor);
            $application->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** Whether this actor may move the application to this status right now. */
    public function allowed(User $actor, NotaryRequest $application, string $status): bool
    {
        if ($application->status === $status) {
            return false;
        }

        if (! $actor->isAdmin()) {
            return $application->canTransitionTo($status);
        }

        // Completion issues the demo certificate, so it must follow an approval.
        return $status !== NotaryRequest::STATUS_COMPLETED || $application->status === NotaryRequest::STATUS_APPROVED;
    }

    /** Why no certificate may be issued: there must be a current file, each still the one uploaded. */
    private function certificateBlocker(NotaryRequest $application): ?string
    {
        $current = $application->currentDocuments()->get();
        $broken = $current->reject(fn (Document $document) => $document->fileMatchesRecord());

        // Only what the citizen was asked for at submission, recorded with the application.
        $missing = array_diff($application->service_details['required_documents'] ?? [], $current->pluck('requirement_key')->all());

        if ($missing) {
            AuditLog::record('certificate.withheld', $application, ['missing' => array_values($missing)]);

            return 'A required document is missing, so no certificate can be issued.';
        }

        if ($current->isNotEmpty() && $broken->isEmpty()) {
            return null;
        }

        AuditLog::record('certificate.withheld', $application, ['documents' => $broken->pluck('id')->all()]);

        return $current->isEmpty()
            ? 'This application has no current document, so no certificate can be issued.'
            : 'A document failed its integrity check, so no certificate can be issued. An administrator can request a correction.';
    }

    /** Demonstration records only: see the public verification page. Replaced files get nothing. */
    private function issueDemoCertificates(NotaryRequest $application): void
    {
        $application->currentDocuments()->get()->each(function (Document $document) {
            $document->forceFill([
                'document_number'   => $document->document_number ?? 'DEMO-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'verification_code' => $document->verification_code ?? Str::upper(Str::random(16)),
                // A code keeps its first issue date, so printed copies still agree with the public check.
                'certified_at'      => $document->certified_at ?? now(),
            ])->save();
        });
    }

    private function event(string $status): string
    {
        return $status === NotaryRequest::STATUS_CORRECTION ? 'correction' : $status;
    }
}
