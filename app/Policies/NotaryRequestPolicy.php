<?php

namespace App\Policies;

use App\Models\NotaryRequest;
use App\Models\User;

/**
 * Who may see and decide an application. Approved owner policy (2026-11-01):
 * a notary acts only on applications assigned to them, and an admin may decide
 * or override any application, always with a reason.
 */
class NotaryRequestPolicy
{
    public function view(User $user, NotaryRequest $application): bool
    {
        return $user->isAdmin()
            || $user->id === (int) $application->user_id
            || $this->isAssignedNotary($user, $application);
    }

    public function decide(User $user, NotaryRequest $application): bool
    {
        return $user->isAdmin() || $this->isAssignedNotary($user, $application);
    }

    public function assign(User $user): bool
    {
        return $user->isAdmin();
    }

    public function resubmit(User $user, NotaryRequest $application): bool
    {
        return $user->id === (int) $application->user_id
            && $application->status === NotaryRequest::STATUS_CORRECTION;
    }

    private function isAssignedNotary(User $user, NotaryRequest $application): bool
    {
        return $user->isApprovedNotary() && $user->id === (int) $application->notary_id;
    }
}
