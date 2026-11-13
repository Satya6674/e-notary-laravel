<?php

namespace App\Policies;

use App\Models\AccountRecoveryRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Only an active administrator decides recovery. The routes are admin-only as well. */
class AccountRecoveryRequestPolicy
{
    public function review(User $actor, AccountRecoveryRequest $recovery): Response
    {
        if (! $actor->isAdmin() || $actor->isDeactivated()) {
            return Response::deny('Only an active administrator can review recovery requests.');
        }

        if (in_array($actor->id, [$recovery->old_user_id, $recovery->requester_id], true)) {
            return Response::deny('You cannot review a request that involves your own account.');
        }

        return $recovery->isOpen() ? Response::allow() : Response::deny('This request has already been decided.');
    }
}
