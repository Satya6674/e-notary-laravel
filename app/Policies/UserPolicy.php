<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Who may delete or restore an account. The routes are admin-only as well. */
class UserPolicy
{
    public function delete(User $actor, User $target): Response
    {
        if (! $this->isActiveAdmin($actor) || ! User::softDeleteSupported() || ! User::accountRecoverySupported()) {
            return Response::deny('Only an active administrator can delete accounts.');
        }

        if ($actor->is($target)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($target->isDeleted()) {
            return Response::deny('This account is already deleted.');
        }

        if ($target->isProtectedAccount()) {
            return Response::deny('This is the permanent administrator account. It cannot be deleted.');
        }

        $otherAdmins = User::where('role', 'admin')->whereKeyNot($target->id)->whereNull('deactivated_at')->exists();

        if ($target->isAdmin() && ! $otherAdmins) {
            return Response::deny('The last active administrator cannot be deleted.');
        }

        return Response::allow();
    }

    public function restore(User $actor, User $target): Response
    {
        if (! $this->isActiveAdmin($actor) || ! User::accountRecoverySupported()) {
            return Response::deny('Only an active administrator can restore accounts.');
        }

        return $target->isDeleted() ? Response::allow() : Response::deny('This account is not deleted.');
    }

    /**
     * Admin > User Verification Management: manual verification and temporary access. Never the
     * actor's own account, never an Admin account, never a deleted or deactivated one. The service
     * checks again under a row lock before it writes anything.
     */
    public function manageVerification(User $actor, User $target): Response
    {
        $why = app(\App\Services\ManualVerificationService::class)->refusal($actor, $target);

        return $why ? Response::deny($why) : Response::allow();
    }

    /** Viewing the verification page: any active Admin, for a Citizen or Notary account. */
    public function viewVerification(User $actor, User $target): Response
    {
        return $this->isActiveAdmin($actor) && in_array($target->role, ['public', 'notary'], true)
            ? Response::allow() : Response::denyAsNotFound();
    }

    private function isActiveAdmin(User $actor): bool
    {
        return $actor->isAdmin() && ! $actor->isDeactivated();
    }
}
