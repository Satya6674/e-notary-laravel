<?php

namespace App\Services;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use App\Notifications\AccountRestoredNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deletes and restores accounts. Rows are locked, so two admins acting together cannot
 * remove the last admin or delete one account twice. The emails go after the commit, and
 * a mail or queue failure never undoes the change: an admin can send the notice again.
 *
 * Deleting a Citizen or Notary releases their email and mobile: the real values move to the
 * deletion record, and the row keeps a placeholder, so the unique indexes on users still
 * guarantee one active owner. An administrator's identity is never released.
 */
class AccountDeletionService
{
    /** @throws AuthorizationException when the policy refuses, with its message */
    public function delete(User $actor, User $target, string $reason): AccountDeletion
    {
        $deletion = DB::transaction(function () use ($actor, $target, $reason) {
            User::where('role', 'admin')->orWhere('id', $target->id)->orderBy('id')->lockForUpdate()->get();
            $target = $target->fresh();

            Gate::forUser($actor->fresh())->authorize('delete', $target);

            return $this->archive($actor, $target, $reason);
        }, 3);

        $this->dispatch($deletion, AccountDeletion::NOTICE_DELETION);

        return $deletion;
    }

    /**
     * The deletion itself, for a caller already inside a transaction. A silent archive sends no
     * notice, for a new account being replaced by its owner's recovered one. Requests this
     * account made end with it, except the one being approved now.
     */
    public function archive(User $actor, User $target, string $reason, bool $silent = false, ?int $approving = null): AccountDeletion
    {
        $at = now();
        $wasDeactivated = $target->deactivated_at !== null;

        // An administrator's identity is never released, including one whose role was taken away
        // before the deletion: otherwise demoting first would free the address for anyone.
        $adminEvidence = $this->adminEvidence($target);
        $keepIdentity = $adminEvidence !== [];

        // Before the identity moves, as these are found by the real address and number.
        $this->revokeAccess($target);
        $this->endOpenRequests($target, $approving);
        // An Admin's temporary access grant must not come back to life after a restore.
        app(ManualVerificationService::class)->endAccessFor($target, $actor->id, ManualVerificationService::CAUSE_ACCOUNT_DELETED);

        $target->forceFill([
            'deleted_at' => $at,
            'deactivated_at' => $target->deactivated_at ?? $at,
            'remember_token' => Str::random(60),
            // Nothing is left to choose, so a later restore does not ask again.
            'previous_account_choice' => $target->previous_account_choice === null ? null : User::CHOICE_NEW,
        ])->save();

        $deletion = AccountDeletion::create([
            'reference' => 'DEL-'.$at->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => $target->id,
            'deleted_by' => $actor->id,
            'name' => $target->name,
            'email' => $target->email,
            'mobile' => $target->mobile,
            'role' => $target->role,
            'reason' => $reason,
            'was_deactivated' => $wasDeactivated,
            'deleted_at' => $at,
            'identity_released_at' => $keepIdentity ? null : $at,
            'notice_suppressed' => $silent,
            'deletion_notification_id' => $silent ? null : (string) Str::uuid(),
        ]);

        if (! $keepIdentity) {
            // A direct update, as the model would clear the verification history with the address.
            User::whereKey($target->id)->update([
                'email' => 'deleted-'.$target->id.'-'.Str::lower(Str::random(12)).'@deleted.invalid',
                'mobile' => null,
                'pending_mobile' => null,
                'pending_mobile_requested_at' => null,
            ]);
        }

        AuditLog::recordOrFail('admin.user_deleted', $target, ['reference' => $deletion->reference, 'role' => $target->role, 'identity_released' => ! $keepIdentity, 'former_admin' => $keepIdentity && ! $target->isAdmin(), 'admin_evidence' => $adminEvidence]);

        return $deletion;
    }

    /** An administrator now, or at some time according to the record. See adminEvidence(). */
    public function everAdmin(User $user): bool
    {
        return $this->adminEvidence($user) !== [];
    }

    /**
     * The names of every trace showing this account is or was an administrator. Each one is
     * checked on its own, so losing one record is not enough to release the identity.
     *
     * The first five are records kept by the application. The sixth, permanent_marker, is kept
     * by database triggers once migration 000023 is applied, and also catches a role changed by
     * direct SQL. None of them survives someone who can drop triggers or alter tables: while the
     * application connects as root, that is anyone holding its database credentials.
     *
     * @return list<string>
     */
    public function adminEvidence(User $user): array
    {
        $roleChanges = AuditLog::where('model_type', 'User')->where('model_id', $user->id)
            ->where(fn ($query) => $query->where('action', AdminRoleService::ROLE_CHANGED)
                ->orWhere(fn ($q) => $q->where('action', 'admin.user_updated')
                    ->where(fn ($role) => $role->where('meta->role_from', 'admin')->orWhere('meta->role_to', 'admin'))));

        $signals = [
            // The model in hand, its loaded state and the stored row: any of them says Admin.
            'current_role' => $user->isAdmin() || $user->getOriginal('role') === 'admin'
                || User::whereKey($user->id)->value('role') === 'admin',
            'role_change_audit' => $roleChanges->exists(),
            'cli_password_reset' => AuditLog::where('model_type', 'User')->where('model_id', $user->id)
                ->where('action', 'admin.password_reset_cli')->exists(),
            // Only an administrator can write an admin.* entry as the actor.
            'admin_actions' => AuditLog::where('user_id', $user->id)->where('action', 'like', 'admin.%')->exists(),
            'admin_deletion_record' => AccountDeletion::where('user_id', $user->id)->where('role', 'admin')->exists(),
            // Migration 000023: set by database triggers whenever the role moves to or from Admin,
            // including outside the application, and never cleared. Absent until it is applied.
            'permanent_marker' => User::adminMarkerSupported()
                && User::whereKey($user->id)->whereNotNull('was_admin_at')->exists(),
        ];

        return array_keys(array_filter($signals));
    }

    /**
     * Brings an archived account back. It returns locked: the old password stops working, the
     * phone must be proven again and a Notary waits for fresh approval. Its owner gets a reset
     * link after the commit and sets a new password from the registered mailbox. The deletion
     * record and its audit history stay as they are; nothing is merged with another account.
     *
     * @param  bool  $rejectionReviewed  the administrator confirmed restoring after a rejected recovery
     *
     * @throws AuthorizationException when the policy or a safety check refuses, with its message
     */
    public function restore(User $actor, User $target, string $reason, bool $rejectionReviewed = false): AccountDeletion
    {
        try {
            $deletion = DB::transaction(function () use ($actor, $target, $reason, $rejectionReviewed) {
                // Locked first, so a second restore of the same account waits and then finds it restored.
                User::whereKey($target->id)->lockForUpdate()->first();
                $target = $target->fresh();

                Gate::forUser($actor->fresh())->authorize('restore', $target);

                $deletion = AccountDeletion::where('user_id', $target->id)->whereNull('restored_at')->latest('id')->lockForUpdate()->firstOrFail();

                if (AccountRecoveryRequest::where('account_deletion_id', $deletion->id)->whereNotNull('open_key')->exists()) {
                    throw new AuthorizationException('This account has an open recovery request. Decide it under Account Recovery Requests.');
                }

                // A rejected recovery was a decision about this person. Overriding it is deliberate.
                $rejected = AccountRecoveryRequest::where('account_deletion_id', $deletion->id)->where('status', AccountRecoveryRequest::REJECTED)->exists();

                if ($rejected && ! $rejectionReviewed) {
                    throw new AuthorizationException('A recovery request for this account was rejected. Confirm that you have reviewed that decision before restoring it.');
                }

                if ($this->identityTaken($deletion)) {
                    throw new AuthorizationException('Another active account now holds this email or mobile number, so it cannot be restored here.');
                }

                $this->returnIdentity($target, $deletion);
                $this->requireFreshProof($target, false);

                $deletion->update(['restored_by' => $actor->id, 'restored_at' => now(), 'restore_notification_id' => (string) Str::uuid()]);

                // The password email gets a known id, so its delivery can be shown and resent.
                $resetId = (string) Str::uuid();

                AuditLog::recordOrFail('admin.user_restored', $target, ['reference' => $deletion->reference, 'role' => $target->role, 'via' => 'admin', 'reason' => $reason, 'after_rejected_recovery' => $rejected, 'fresh_proof_required' => true, 'reset_notification_id' => $resetId]);

                return [$deletion, $resetId];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new AuthorizationException('Another active account now holds this email or mobile number, so it cannot be restored here.');
        }

        [$deletion, $resetId] = $deletion;

        // After the commit: the restore notice, then the way back in.
        $this->dispatch($deletion, AccountDeletion::NOTICE_RESTORE);
        $this->sendResetLink($deletion->user_id, $resetId);

        return $deletion;
    }

    public const RESET_LINK_RESENT = 'admin.restore_reset_link_resent';

    public const RESET_LINK_REFUSED = 'admin.restore_reset_link_refused';

    public const RESET_LINK_FAILED = 'admin.restore_reset_link_failed';

    /**
     * Where the password email of a plain restore stands, and whether an administrator may
     * replace it now. It may be replaced only when the current link cannot work any more: never
     * queued, failed, blocked by the allowlist, or expired. A link that was sent or is waiting in
     * the queue, and still works, is left alone, so two working links never exist.
     *
     * @return array{status: string, resendable: bool, message: ?string}
     */
    public function resetLinkState(AccountDeletion $deletion): array
    {
        $deny = fn (string $status, string $message) => ['status' => $status, 'resendable' => false, 'message' => $message];
        $user = User::whereKey($deletion->user_id)->first();

        if ($deletion->restored_at === null) {
            return $deny('not_restored', 'This account has not been restored.');
        }

        if (! $user || $user->isDeleted() || AccountDeletion::where('user_id', $user->id)->where('id', '>', $deletion->id)->exists()) {
            return $deny('superseded', 'This account was deleted again after this restore.');
        }

        if (User::accountRecoverySupported() && AccountRecoveryRequest::where('account_deletion_id', $deletion->id)->where('status', AccountRecoveryRequest::APPROVED)->exists()) {
            return $deny('recovery', 'This account was restored through a recovery request. Send its password email again from that request.');
        }

        if (! $user->mustResetPassword()) {
            return $deny('used', 'The person has already set a new password, so no link is needed.');
        }

        $delivery = $this->resetDeliveryStatus($deletion, $user);
        $issued = DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->value('created_at');
        $working = $issued && Carbon::parse($issued)->addMinutes((int) config('auth.passwords.users.expire', 60))->isFuture();

        if ($working && in_array($delivery, ['sent', 'queued'], true)) {
            return $deny($delivery, $delivery === 'sent'
                ? 'The current link was accepted by the mail service and still works. It can be replaced once it expires.'
                : 'The current link is waiting in the queue and still works. It can be replaced once it fails or expires.');
        }

        // The password broker's own pause between two links for one account.
        if ($issued && Carbon::parse($issued)->addSeconds((int) config('auth.passwords.users.throttle', 60))->isFuture()) {
            return $deny($delivery, 'A link was created less than a minute ago. Please wait a minute and try again.');
        }

        return ['status' => ! $working && in_array($delivery, ['sent', 'queued'], true) ? 'expired' : $delivery, 'resendable' => true, 'message' => null];
    }

    /**
     * Sends a new password link for a plain restore, replacing the old one: the broker keeps one
     * token per account, so the earlier link stops working. The account stays locked until the
     * person uses the new link. Every attempt is audited, refused ones too; the token itself is
     * never put in the audit entry, a flash message, a URL or a log.
     *
     * @return bool true when the new email was queued
     *
     * @throws AuthorizationException when the resend is not allowed now, with its reason
     */
    public function resendResetLink(User $actor, AccountDeletion $deletion): bool
    {
        try {
            [$userId, $token, $resetId] = DB::transaction(function () use ($actor, $deletion) {
                // The acting administrator and the account, in id order (the order deactivation and
                // role changes use), then the deletion. A second administrator waits here and then
                // finds a new link already made. The actor row is locked too, so a deactivation in
                // progress, by any path, finishes first and is seen below.
                User::whereIn('id', [$actor->id, $deletion->user_id])->orderBy('id')->lockForUpdate()->get();
                $locked = AccountDeletion::whereKey($deletion->id)->lockForUpdate()->firstOrFail();
                $freshActor = User::whereKey($actor->id)->lockForUpdate()->first();

                if (! $freshActor || ! $freshActor->isAdmin() || $freshActor->isDeactivated()) {
                    throw new AuthorizationException('Only an active administrator can send a new password link.');
                }

                $state = $this->resetLinkState($locked);

                if (! $state['resendable']) {
                    throw new AuthorizationException($state['message']);
                }

                $user = User::findOrFail($locked->user_id);
                $token = Password::broker()->createToken($user);
                $resetId = (string) Str::uuid();

                AuditLog::recordOrFail(self::RESET_LINK_RESENT, $user, ['reference' => $locked->reference, 'reset_notification_id' => $resetId, 'previous_status' => $state['status']], $actor->id);

                return [$user->id, $token, $resetId];
            }, 3);
        } catch (AuthorizationException $e) {
            AuditLog::record(self::RESET_LINK_REFUSED, $deletion->user, ['reference' => $deletion->reference, 'reason' => $e->getMessage()], $actor->id);

            throw $e;
        }

        if ($this->sendResetLink($userId, $resetId, $token)) {
            return true;
        }

        AuditLog::record(self::RESET_LINK_FAILED, $deletion->user, ['reference' => $deletion->reference, 'reset_notification_id' => $resetId], $actor->id);

        return false;
    }

    /** sent, queued, failed, blocked, not_queued, or untracked for a restore made before tracking. */
    private function resetDeliveryStatus(AccountDeletion $deletion, User $user): string
    {
        $id = AuditLog::where('model_type', 'User')->where('model_id', $user->id)
            ->whereIn('action', ['admin.user_restored', self::RESET_LINK_RESENT])
            ->where('meta->reference', $deletion->reference)
            ->latest('id')->get()
            ->map(fn (AuditLog $entry) => $entry->meta['reset_notification_id'] ?? null)->filter()->first();

        if (! $id) {
            return 'untracked';
        }

        return match (MessageDelivery::where('notification_id', $id)->value('status')) {
            MessageDelivery::ACCEPTED => 'sent',
            MessageDelivery::FAILED => 'failed',
            MessageDelivery::BLOCKED => 'blocked',
            MessageDelivery::QUEUED, MessageDelivery::SENDING => 'queued',
            default => 'not_queued',
        };
    }

    /**
     * A restored account proves itself again. The current password is retired, kept only as a
     * hash to refuse its reuse, and replaced by a random one nobody knows; a reset is required.
     * Notary approval ends. The phone proof ends too, unless the caller has just seen that same
     * number proven by genuine SMS, because a number can change hands while an account is closed.
     */
    public function requireFreshProof(User $user, bool $keepPhoneProof): void
    {
        $current = User::whereKey($user->id)->first();

        User::whereKey($user->id)->update([
            // Restored twice with no reset between: the current hash is the random one, not a real password.
            'retired_passwords' => json_encode($current->mustResetPassword() ? (array) $current->retired_passwords : [...(array) $current->retired_passwords, $current->password]),
            'password' => Hash::make(Str::random(64)),
            'password_reset_required_at' => now(),
            'remember_token' => Str::random(60),
            'notary_approved_at' => null,
        ] + ($keepPhoneProof ? [] : $this->unprovenPhone()));
    }

    private function unprovenPhone(): array
    {
        // The email fallback and an Admin manual check covered the old number too. Their history
        // rows stay in verification_events; only the current state is cleared.
        return (User::mobileVerificationSupported() ? ['mobile_verified_at' => null] : [])
            + (User::verificationStatusSupported() ? ['mobile_verification_method' => null, 'mobile_verification_status' => null, 'mobile_simulated_at' => null] : [])
            + (User::mobileFallbackSupported() ? ['mobile_sms_status' => null, 'mobile_fallback_verified_at' => null, 'mobile_fallback_number' => null] : [])
            + (User::mobileManualSupported() ? ['mobile_manual_verified_at' => null, 'mobile_manual_number' => null] : []);
    }

    /**
     * A fresh reset link to the registered address, through the password broker: the token is
     * stored hashed, expires, and travels only inside the email, which is encrypted while queued.
     * If this fails the account stays locked, and Forgot Password by email still works.
     */
    private function sendResetLink(int $userId, string $notificationId, ?string $token = null): bool
    {
        try {
            $user = User::findOrFail($userId);

            if ($user->isDeleted()) {
                return false;
            }

            $notification = new ResetPasswordNotification($token ?? Password::broker()->createToken($user));
            $notification->id = $notificationId;
            $user->notify($notification);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Puts the real email and mobile back, if they left. Proof is handled by requireFreshProof(). */
    public function returnIdentity(User $target, AccountDeletion $deletion): void
    {
        User::whereKey($target->id)->update([
            'deleted_at' => null,
            // An earlier deactivation returns with the account.
            'deactivated_at' => $deletion->was_deactivated ? $target->deactivated_at : null,
        ] + ($deletion->identity_released_at ? ['email' => $deletion->email, 'mobile' => $deletion->mobile] : []));
    }

    /** Sends a notice again, only if the last one did not get through. True when queued. */
    public function retry(AccountDeletion $deletion, string $notice): bool
    {
        $retried = DB::transaction(function () use ($deletion, $notice) {
            $current = AccountDeletion::whereKey($deletion->id)->lockForUpdate()->firstOrFail();

            if (! $current->canRetry($notice)) {
                return null;
            }

            $current->update([$current->noticeColumn($notice) => (string) Str::uuid()]);

            AuditLog::record('admin.user_notice_retried', $current->user, ['reference' => $current->reference, 'notice' => $notice]);

            return $current;
        });

        if (! $retried) {
            return false;
        }

        return $this->dispatch($retried, $notice);
    }

    public function dispatch(AccountDeletion $deletion, string $notice): bool
    {
        $id = (string) $deletion->{$deletion->noticeColumn($notice)};

        try {
            $deletion->user->notify($notice === AccountDeletion::NOTICE_RESTORE
                ? new AccountRestoredNotification($deletion->id, $id)
                : new AccountDeletedNotification($deletion->id, $id));

            return true;
        } catch (Throwable $e) {
            // The change stands. The missing delivery record shows as Not queued, with a retry.
            report($e);

            return false;
        }
    }

    /** True when an active account other than the deleted one holds the old identity. */
    private function identityTaken(AccountDeletion $deletion): bool
    {
        return User::whereNull('deleted_at')->whereKeyNot($deletion->user_id)
            ->where(fn ($query) => $query->whereRaw('LOWER(email) = ?', [Str::lower($deletion->email)])
                ->when($deletion->mobile, fn ($q) => $q->orWhere('mobile', $deletion->mobile)))
            ->exists();
    }

    private function endOpenRequests(User $target, ?int $approving): void
    {
        $open = AccountRecoveryRequest::where('requester_id', $target->id)->whereNotNull('open_key')
            ->when($approving, fn ($query) => $query->whereKeyNot($approving))->lockForUpdate()->get();

        foreach ($open as $recovery) {
            $recovery->update(['status' => AccountRecoveryRequest::CANCELLED, 'open_key' => null, 'decision_reason' => 'The account that made this request was deleted.']);

            AuditLog::record('account.recovery_cancelled', $recovery, ['reference' => $recovery->reference, 'reason' => 'requester_deleted']);
        }
    }

    /** The middleware ends live sessions at their next request. This closes the other ways in. */
    private function revokeAccess(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();

        if ($user->mobile) {
            DB::table('otp_verifications')->where('mobile', $user->mobile)->delete();
        }

        // Any unused registration email code of this account.
        DB::table('otp_verifications')->where('mobile', OtpService::emailKey($user))->delete();
    }
}
