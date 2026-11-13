<?php

namespace App\Services;

use App\Models\AccountRecoveryRequest;
use App\Models\NotaryRequest;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Tells staff that something needs them. Every message resolves each recipient's own
 * address and carries a reference only: no Citizen name, contact detail or document,
 * so an administrator's inbox does not become a copy of the application.
 */
class StaffNotifier
{
    public function applicationSubmitted(NotaryRequest $application): void
    {
        $this->admins('New application received', $application->request_number, route('admin.requests.show', $application->id));
    }

    /** Notary accounts need an administrator's approval. Citizens only when configured. */
    public function accountRegistered(User $user): void
    {
        if ($user->role === 'notary') {
            $this->admins('New Notary account awaiting approval', 'Account #'.$user->id, route('admin.notaries.index'));
        } elseif (config('mail.admin_new_citizens')) {
            $this->admins('New Citizen account', 'Account #'.$user->id, route('admin.users.show', $user));
        }
    }

    public function recoveryRequested(AccountRecoveryRequest $recovery): void
    {
        $this->admins('New account recovery request', $recovery->reference, route('admin.recovery.show', $recovery));
    }

    public function notaryReviewed(User $notary, bool $approved, User $actor, ?string $reason = null, bool $wasApproved = true): void
    {
        $title = $approved ? 'Notary account approved' : ($wasApproved ? 'Notary account approval withdrawn' : 'Notary application rejected');

        // The Notary is told the reason; the copy to other administrators carries only the account number.
        $body = 'Account #'.$notary->id.($reason !== null ? '. Reason: '.$reason : '');

        // The Citizen dashboard refuses a Notary, so each notice opens the Notary's own page.
        $notary->notify(new PortalNotification($title, $body, route($approved ? 'notary.dashboard' : 'notary.pending')));
        $this->admins($title, 'Account #'.$notary->id, route('admin.notaries.index'), $actor);
    }

    /** The Citizen is told a notary is reviewing, not which one. */
    public function notaryAssigned(NotaryRequest $application): void
    {
        $application->user?->notify(new PortalNotification(
            'Notary assigned to your application', $application->request_number, route('user.requests.show', $application->id)
        ));
    }

    public function notaryRemoved(NotaryRequest $application, ?User $notary): void
    {
        $notary?->notify(new PortalNotification('Application reassigned', $application->request_number, route('notary.requests')));
    }

    /** A corrected application goes to the notary reviewing it, or to the admin queue if none. */
    public function correctionReturned(NotaryRequest $application): void
    {
        $title = 'Corrected application ready for review';

        if ($application->notary) {
            $application->notary->notify(new PortalNotification($title, $application->request_number, route('notary.request.show', $application->id)));
        } else {
            $this->admins($title, $application->request_number, route('admin.requests.show', $application->id));
        }
    }

    /** Someone else changed an application this notary is responsible for. */
    public function statusChanged(NotaryRequest $application, User $actor): void
    {
        if ($application->notary && $application->notary->id !== $actor->id) {
            $application->notary->notify(new PortalNotification(
                'Application status updated', $application->request_number, route('notary.request.show', $application->id)
            ));
        }
    }

    public function incidentOpened(SecurityIncident $incident, User $actor): void
    {
        $this->admins('Security incident recorded', 'Incident #'.$incident->id, route('admin.security.incidents'), $actor);
    }

    private function admins(string $title, string $body, string $url, ?User $except = null): void
    {
        // Before the deactivation migration the column is missing, and asking for it would fail
        // the action that called this, such as a sign-up that has already saved its account.
        $admins = User::where('role', 'admin')
            ->when(Schema::hasColumn('users', 'deactivated_at'), fn ($query) => $query->whereNull('deactivated_at'))
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->get();

        Notification::send($admins, new PortalNotification($title, $body, $url));
    }
}
