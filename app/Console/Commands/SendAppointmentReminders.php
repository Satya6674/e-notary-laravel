<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Console\Command;

/**
 * Reminds both people about confirmed appointments in the next 24 hours, once each.
 * The in-app notice is immediate; the email copy waits for the queue worker.
 *
 * Who qualifies (all times are stored and compared in UTC; the reminder shows Indian time):
 *  - status "confirmed" (not requested, completed or cancelled);
 *  - scheduled_at between now and now + 24 h, so a past appointment never qualifies and no
 *    historical reminder is ever replayed, however long the scheduler was stopped;
 *  - reminded_at still empty: claimed atomically before sending, so a repeated, overlapping or
 *    retried run sends nothing twice;
 *  - its application still exists and is not rejected or completed (an obsolete appointment).
 * A deactivated or deleted account is not emailed; the reminder still counts as done.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'enotary:appointment-reminders';

    protected $description = 'Remind citizens and notaries about confirmed appointments in the next 24 hours';

    /** Applications whose appointments are obsolete: nothing is left to meet about. */
    public const CLOSED_REQUEST_STATUSES = [NotaryRequest::STATUS_REJECTED, NotaryRequest::STATUS_COMPLETED];

    public function handle(): int
    {
        $due = Appointment::with(['request', 'user', 'notary'])
            ->where('status', 'confirmed')
            ->whereNull('reminded_at')
            ->whereBetween('scheduled_at', [now(), now()->addDay()])
            ->whereHas('request', fn ($q) => $q->whereNotIn('status', self::CLOSED_REQUEST_STATUSES))
            ->get();

        $sent = 0;

        foreach ($due as $appointment) {
            // Claim the reminder first. If another run (or a retry) got there, this one sends nothing.
            if (Appointment::whereKey($appointment->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) !== 1) {
                continue;
            }

            $sent++;
            $notice = new PortalNotification(
                'Appointment reminder',
                $appointment->scheduled_at->ist().' IST ('.$appointment->request?->request_number.')',
                route('appointments.index')
            );

            if (self::reachable($appointment->user)) {
                $appointment->user->notify($notice);
            }

            if (self::reachable($appointment->notary)) {
                $appointment->notary->notify(clone $notice);
            }

            AuditLog::record('appointment.reminded', $appointment);
        }

        $this->info('Reminders sent: '.$sent);

        return self::SUCCESS;
    }

    /** An account that may still be contacted: present, not deactivated and not deleted. */
    private static function reachable(?User $user): bool
    {
        return $user !== null && ! $user->isDeactivated();
    }
}
