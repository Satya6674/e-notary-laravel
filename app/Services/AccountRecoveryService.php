<?php

namespace App\Services;

use App\Exceptions\AccountRecoveryRefused;
use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\Appointment;
use App\Models\NotaryRequest;
use App\Models\ServiceDraft;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds a previous account after identity checks, and carries a recovery request through
 * review. Accounts are never merged: approval archives the new account and restores the
 * old one in one transaction, or nothing happens.
 */
class AccountRecoveryService
{
    public function __construct(private AccountDeletionService $deletions)
    {
    }

    /**
     * Settles whether this person has a previous account to decide about. Only accounts marked
     * at registration are looked at, once, and a request that stopped applying releases them.
     */
    public function resolveChoice(User $user): ?string
    {
        if (! in_array($user->previous_account_choice, [User::CHOICE_CHECK, User::CHOICE_PENDING], true)) {
            return $user->previous_account_choice;
        }

        $choice = DB::transaction(function () use ($user) {
            $current = User::whereKey($user->id)->lockForUpdate()->first();

            if (! in_array($current->previous_account_choice, [User::CHOICE_CHECK, User::CHOICE_PENDING], true)) {
                return $current->previous_account_choice;
            }

            $choice = $this->detect($current) ? User::CHOICE_PENDING : User::CHOICE_NONE;

            if ($choice !== $current->previous_account_choice) {
                $current->forceFill(['previous_account_choice' => $choice])->save();
            }

            return $choice;
        });

        $user->forceFill(['previous_account_choice' => $choice])->syncOriginalAttribute('previous_account_choice');

        return $choice;
    }

    /**
     * The newest deleted Citizen or Notary matching this person's proven identity. A mobile counts
     * only after genuine SMS, and only against an account that had proven that number itself: a
     * number is reissued, and the local bridge and the mock prove nothing about a phone. An
     * administrator's identity is never released, and a blank account replaced during an earlier
     * recovery is not offered back.
     */
    public function detect(User $user): ?AccountDeletion
    {
        // Before migration 000021 the columns below do not exist, and there is nothing to recover.
        if (! User::accountRecoverySupported()) {
            return null;
        }

        $byEmail = $user->hasVerifiedEmail();
        $byMobile = $user->mobile && $this->hasGenuineMobile($user);

        if (! $byEmail && ! $byMobile) {
            return null;
        }

        return AccountDeletion::whereNull('restored_at')->whereNotNull('identity_released_at')
            ->where('role', '!=', 'admin')->where('notice_suppressed', false)
            ->where('user_id', '!=', $user->id)
            ->where(function ($query) use ($user, $byEmail, $byMobile) {
                $query->when($byEmail, fn ($q) => $q->whereRaw('LOWER(email) = ?', [Str::lower($user->email)]))
                    ->when($byMobile, fn ($q) => $q->orWhere(fn ($match) => $match->where('mobile', $user->mobile)
                        ->whereHas('user', fn ($old) => $old->whereNotNull('mobile_verified_at')
                            ->where(fn ($proof) => $proof->whereNull('mobile_verification_method')->orWhere('mobile_verification_method', '!=', User::METHOD_MOCK_SMS)))));
            })
            ->latest('id')->first();
    }

    public function hasGenuineMobile(User $user): bool
    {
        return $user->hasVerifiedMobile() && $user->mobile_verification_method === User::METHOD_SMS;
    }

    /** True when the address matched, so the fresh code can go by email. */
    public function matchedByEmail(User $user, AccountDeletion $deletion): bool
    {
        return Str::lower($user->email) === Str::lower($deletion->email);
    }

    /**
     * Records the request after a fresh code was checked. Null if it no longer applies.
     *
     * The code was emailed to the address of the archived account $codeFor, so it proves that
     * mailbox and nothing else: the request may only be for that same account. A mobile match
     * alone is never enough (see PreviousAccountController::code()).
     */
    public function submit(User $user, int $codeFor): ?AccountRecoveryRequest
    {
        try {
            $recovery = DB::transaction(function () use ($user, $codeFor) {
                $user = User::whereKey($user->id)->lockForUpdate()->first();
                $deletion = $this->detect($user);

                if (! $deletion || $deletion->id !== $codeFor || $user->previous_account_choice !== User::CHOICE_PENDING) {
                    return null;
                }

                $byEmail = $this->matchedByEmail($user, $deletion);
                $byMobile = $user->mobile !== null && $user->mobile === $deletion->mobile && $this->hasGenuineMobile($user);
                $local = ! app()->isProduction();

                $recovery = AccountRecoveryRequest::create([
                    'reference' => 'REC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                    'account_deletion_id' => $deletion->id,
                    'old_user_id' => $deletion->user_id,
                    'requester_id' => $user->id,
                    'applicant_name' => $user->name,
                    'verified_email' => $user->email,
                    'verified_mobile' => $user->mobile,
                    // How the person was found. The proof is always the previous account's mailbox.
                    'match_type' => $byEmail && $byMobile ? 'both' : ($byEmail ? 'email' : 'mobile'),
                    'email_proof' => $local ? AccountRecoveryRequest::PROOF_EMAIL_LOCAL : AccountRecoveryRequest::PROOF_EMAIL_CODE,
                    'mobile_proof' => $this->mobileProof($user),
                    'environment' => $local ? AccountRecoveryRequest::ENV_LOCAL : AccountRecoveryRequest::ENV_PRODUCTION,
                    'status' => AccountRecoveryRequest::PENDING,
                    'open_key' => $deletion->id,
                    'requested_notification_id' => (string) Str::uuid(),
                    // Reached through a mobile number, so the old mailbox has not been heard from.
                    'alert_notification_id' => $byEmail ? null : (string) Str::uuid(),
                ]);

                $user->forceFill(['previous_account_choice' => User::CHOICE_RECOVERY])->save();

                AuditLog::record('account.recovery_requested', $recovery, ['reference' => $recovery->reference, 'match' => $recovery->match_type, 'email_proof' => $recovery->email_proof, 'code_sent_to' => $byEmail ? 'own_address' : 'previous_account_address', 'mobile_proof' => $recovery->mobile_proof, 'environment' => $recovery->environment], $user->id);

                return $recovery;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Someone else has an open request for this account.
            return null;
        }

        if ($recovery) {
            $this->dispatch($recovery, 'requested');

            if ($recovery->alert_notification_id) {
                $this->dispatch($recovery, 'alert');
            }

            try {
                app(StaffNotifier::class)->recoveryRequested($recovery);
            } catch (Throwable $e) {
                // The request stands, and it is listed for administrators either way.
                report($e);
            }
        }

        return $recovery;
    }

    private function mobileProof(User $user): string
    {
        return match (true) {
            $this->hasGenuineMobile($user) => AccountRecoveryRequest::PROOF_GENUINE_SMS,
            $user->hasVerifiedMobile() => AccountRecoveryRequest::PROOF_MOCK_SMS,
            $user->mobileSimulationAccepted() => AccountRecoveryRequest::PROOF_SIMULATED,
            default => AccountRecoveryRequest::PROOF_NONE,
        };
    }

    /** The person continues with their new account, and the old one stays archived. */
    public function cancel(User $user): void
    {
        DB::transaction(function () use ($user) {
            // By key, then the account: the order approval takes them in.
            $id = AccountRecoveryRequest::where('requester_id', $user->id)->whereNotNull('open_key')->value('id');
            $recovery = $id ? AccountRecoveryRequest::whereKey($id)->lockForUpdate()->first() : null;
            $current = User::whereKey($user->id)->lockForUpdate()->first();

            // An administrator got there first, and that decision stands.
            if (! $recovery?->open_key || $current->previous_account_choice !== User::CHOICE_RECOVERY) {
                return;
            }

            $recovery->update(['status' => AccountRecoveryRequest::CANCELLED, 'open_key' => null]);
            $current->forceFill(['previous_account_choice' => User::CHOICE_NEW])->save();

            AuditLog::record('account.recovery_cancelled', $recovery, ['reference' => $recovery->reference, 'reason' => 'by_requester'], $user->id);
        }, 3);
    }

    /** Only from the choice itself, so a request made in another tab keeps its hold. */
    public function chooseNewAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $current = User::whereKey($user->id)->lockForUpdate()->first();

            if ($current->previous_account_choice !== User::CHOICE_PENDING) {
                return;
            }

            $current->forceFill(['previous_account_choice' => User::CHOICE_NEW])->save();

            AuditLog::record('account.previous_declined', $current, [], $user->id);
        }, 3);
    }

    /** @throws AuthorizationException */
    public function startReview(User $admin, AccountRecoveryRequest $recovery): void
    {
        DB::transaction(function () use ($admin, $recovery) {
            $recovery = AccountRecoveryRequest::whereKey($recovery->id)->lockForUpdate()->first();

            Gate::forUser($admin)->authorize('review', $recovery);

            $recovery->update(['status' => AccountRecoveryRequest::UNDER_REVIEW]);

            AuditLog::record('account.recovery_under_review', $recovery, ['reference' => $recovery->reference]);
        }, 3);
    }

    /** @throws AuthorizationException */
    public function reject(User $admin, AccountRecoveryRequest $recovery, string $reason): void
    {
        $this->close($admin, $recovery, $reason, AccountRecoveryRequest::REJECTED);
    }

    /**
     * Ends the request without a verdict on the person, such as when a conflict cannot be
     * resolved. They are told in the same way as a rejection, with the reason.
     *
     * @throws AuthorizationException
     */
    public function cancelByAdmin(User $admin, AccountRecoveryRequest $recovery, string $reason): void
    {
        $this->close($admin, $recovery, $reason, AccountRecoveryRequest::CANCELLED);
    }

    private function close(User $admin, AccountRecoveryRequest $recovery, string $reason, string $status): void
    {
        $recovery = DB::transaction(function () use ($admin, $recovery, $reason, $status) {
            $recovery = AccountRecoveryRequest::whereKey($recovery->id)->lockForUpdate()->first();

            Gate::forUser($admin)->authorize('review', $recovery);

            // Closing (not rejecting) a request that is too old is not a verdict on the person: it
            // is recorded as expired, and they may ask again, which needs a fresh emailed code.
            $expired = $status === AccountRecoveryRequest::CANCELLED && $recovery->isExpired();
            $status = $expired ? AccountRecoveryRequest::EXPIRED : $status;

            $recovery->update([
                'status' => $status,
                'open_key' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'decision_reason' => $reason,
                'rejected_notification_id' => (string) Str::uuid(),
            ]);

            // Expired: checked again at the next visit, so the choice and a fresh code come back.
            // Otherwise the person continues with their new account.
            $recovery->requester->forceFill(['previous_account_choice' => $expired ? User::CHOICE_CHECK : User::CHOICE_NEW])->save();

            AuditLog::record(match ($status) {
                AccountRecoveryRequest::REJECTED => 'account.recovery_rejected',
                AccountRecoveryRequest::EXPIRED => 'account.recovery_expired',
                default => 'account.recovery_cancelled',
            }, $recovery, ['reference' => $recovery->reference, 'reason' => $status === AccountRecoveryRequest::REJECTED ? null : 'by_admin', 'age_days' => (int) $recovery->created_at->diffInDays(now())]);

            return $recovery;
        }, 3);

        $this->dispatch($recovery, 'rejected');
    }

    /**
     * Scheduled task: close every open request that has passed its 30-day limit, exactly as an
     * administrator closing it would (status EXPIRED, the person may ask again with fresh proof),
     * but with no administrator recorded. Safe to run any number of times: each row is locked
     * and checked again, so a request is closed once, and one decided meanwhile is left alone.
     * Nothing is deleted.
     */
    public function expireOverdue(): int
    {
        if (! User::accountRecoverySupported()) {
            return 0;
        }

        $closed = 0;
        $candidates = AccountRecoveryRequest::whereIn('status', [AccountRecoveryRequest::PENDING, AccountRecoveryRequest::UNDER_REVIEW])
            ->where('created_at', '<=', now()->subDays(AccountRecoveryRequest::MAX_AGE_DAYS))
            ->pluck('id');

        foreach ($candidates as $id) {
            $recovery = DB::transaction(function () use ($id) {
                $recovery = AccountRecoveryRequest::whereKey($id)->lockForUpdate()->first();

                if (! $recovery || ! in_array($recovery->status, [AccountRecoveryRequest::PENDING, AccountRecoveryRequest::UNDER_REVIEW], true) || ! $recovery->isExpired()) {
                    return null;
                }

                $recovery->update([
                    'status' => AccountRecoveryRequest::EXPIRED,
                    'open_key' => null,
                    'reviewed_at' => now(),
                    'decision_reason' => 'Closed automatically: the request was more than '.AccountRecoveryRequest::MAX_AGE_DAYS.' days old.',
                    'rejected_notification_id' => (string) Str::uuid(),
                ]);

                $recovery->requester?->forceFill(['previous_account_choice' => User::CHOICE_CHECK])->save();

                AuditLog::record('account.recovery_expired', $recovery, [
                    'reference' => $recovery->reference, 'reason' => 'scheduled', 'age_days' => (int) $recovery->created_at->diffInDays(now()),
                ], null);

                return $recovery;
            }, 3);

            if ($recovery) {
                $closed++;
                $this->dispatch($recovery, 'rejected');
            }
        }

        return $closed;
    }

    /**
     * What stands in the way. "accounts" are active accounts holding the old identity, and
     * "blocked" says why approval is not safe, in which case the request can only be closed.
     *
     * @return array{accounts: Collection<int, User>, blank_request_only: bool, needs_confirmation: bool, blocked: ?string, stays_deactivated: bool}
     */
    public function assess(AccountRecoveryRequest $recovery): array
    {
        $deletion = $recovery->deletion;

        $accounts = User::whereNull('deleted_at')
            ->where(fn ($query) => $query->whereRaw('LOWER(email) = ?', [Str::lower($deletion->email)])
                ->when($deletion->mobile, fn ($q) => $q->orWhere('mobile', $deletion->mobile)))
            ->orderBy('id')->get();

        $others = $accounts->where('id', '!=', $recovery->requester_id);
        $requester = $accounts->firstWhere('id', $recovery->requester_id);
        $old = User::whereKey($recovery->old_user_id)->first();
        $asker = User::whereKey($recovery->requester_id)->first();

        $blocked = match (true) {
            // Integrity first: a request must name the very account its deletion record is about,
            // or approval would put one person's identity on another person's account.
            ! $old || (int) $recovery->old_user_id !== (int) $deletion->user_id => 'This request does not match the archived account it names, so it cannot be approved. Close it.',
            // The proof behind it is too old to rely on. Closing it lets the person ask again, with fresh proof.
            $recovery->isExpired() => 'This request is more than '.AccountRecoveryRequest::MAX_AGE_DAYS.' days old, so its proof is too old to rely on. Close it: the person can then ask again with fresh proof.',
            // Proof recorded on a development copy (log mail, local checks) proves nothing on a real site.
            app()->isProduction() && $recovery->environment !== AccountRecoveryRequest::ENV_PRODUCTION => 'This request was made on a development copy of the site, where its checks prove nothing. Close it: the person can ask again here.',
            // Made before the previous account's mailbox was required, or forged: a phone number
            // alone proves nothing about who owns the account.
            $recovery->email_proof === AccountRecoveryRequest::PROOF_NONE => 'This request was made with phone proof only, which is not enough. Close it: the person can ask again and prove the previous account\'s email address.',
            $deletion->role === 'admin' || ! $deletion->identity_released_at => 'An administrator account cannot be recovered here.',
            // The archived account itself, by any record (its role may have been changed while archived).
            $this->deletions->everAdmin($old) => 'The archived account is, or was, an administrator account, so it cannot be recovered here.',
            // An administrator deactivated this person after they asked: that decision comes first.
            ! $asker || $asker->isDeactivated() => 'The person who asked is deactivated. Reactivate their account first if that was a mistake, or close the request.',
            $deletion->restored_at !== null => 'The original account is no longer archived.',
            // A former administrator too: archiving one keeps its identity, so the restore would collide.
            $accounts->contains(fn (User $user) => $this->deletions->everAdmin($user)) => 'An administrator account, current or former, holds this identity, so it cannot be archived here.',
            ! $requester || ! $this->stillProven($requester, $recovery) => 'The person who asked no longer holds the email or mobile number they proved, so their proof no longer stands.',
            $others->count() > 1 => 'More than one other account holds this identity, so this cannot be resolved safely here.',
            $accounts->contains(fn (User $user) => $this->hasOpenWork($user)) => 'An account holding this identity has open applications. Finish or reassign them first.',
            default => null,
        };

        $blankRequestOnly = $others->isEmpty() && $accounts->every(fn (User $user) => $this->isBlank($user));

        return [
            'accounts' => $accounts,
            'blank_request_only' => $blankRequestOnly,
            'needs_confirmation' => ! $blankRequestOnly,
            'blocked' => $blocked,
            'stays_deactivated' => (bool) $deletion->was_deactivated,
        ];
    }

    /**
     * Archives whoever holds the identity and restores the original account, all or nothing.
     *
     * @throws AuthorizationException when not allowed
     * @throws AccountRecoveryRefused with a message for the admin, when it is not safe
     */
    public function approve(User $admin, AccountRecoveryRequest $recovery, bool $conflictConfirmed, ?string $conflictReason): void
    {
        try {
            [$recovery, $deletion, $archived] = DB::transaction(function () use ($admin, $recovery, $conflictConfirmed, $conflictReason) {
                $recovery = AccountRecoveryRequest::whereKey($recovery->id)->lockForUpdate()->first();

                Gate::forUser($admin)->authorize('review', $recovery);

                $deletion = AccountDeletion::whereKey($recovery->account_deletion_id)->lockForUpdate()->first();
                $recovery->setRelation('deletion', $deletion);

                $this->lockIdentity($recovery, $deletion);

                $assessment = $this->assess($recovery);
                $old = User::whereKey($recovery->old_user_id)->first();

                if ($assessment['blocked']) {
                    throw new AccountRecoveryRefused($assessment['blocked']);
                }

                if ($assessment['needs_confirmation'] && (! $conflictConfirmed || blank($conflictReason))) {
                    throw new AccountRecoveryRefused('This request has an identity conflict. Confirm the resolution and give a reason.');
                }

                if (! $old->isDeleted()) {
                    throw new AccountRecoveryRefused('The original account is no longer archived.');
                }

                $archived = [];

                foreach ($assessment['accounts'] as $account) {
                    $silent = $account->id === $recovery->requester_id;
                    $archived[] = $this->deletions->archive($admin, $account, 'Archived during account recovery '.$recovery->reference, $silent, $recovery->id);
                }

                $this->restoreOriginal($admin, $old, $deletion, $recovery);

                $recovery->update([
                    'status' => AccountRecoveryRequest::APPROVED,
                    'open_key' => null,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'conflict_resolution' => $assessment['needs_confirmation'] ? $conflictReason : null,
                    'approved_notification_id' => (string) Str::uuid(),
                    'reset_notification_id' => (string) Str::uuid(),
                ]);

                AuditLog::record('account.recovery_approved', $recovery, ['reference' => $recovery->reference, 'old_user' => $old->id, 'archived_users' => $assessment['accounts']->pluck('id')->all(), 'conflict' => $assessment['needs_confirmation']]);

                return [$recovery, $deletion->fresh(), $archived];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new AccountRecoveryRefused('The identity was taken while approving, so nothing was changed. Review the request again.');
        }

        foreach ($archived as $record) {
            if (! $record->notice_suppressed) {
                $this->deletions->dispatch($record, AccountDeletion::NOTICE_DELETION);
            }
        }

        // Separate on purpose: the decision, the restoration and the way back in.
        $this->dispatch($recovery, 'approved');
        $this->deletions->dispatch($deletion, AccountDeletion::NOTICE_RESTORE);
        $this->sendReset($recovery);
    }

    /** Sends a recovery notice again, only if the last one did not get through. */
    public function retry(AccountRecoveryRequest $recovery, string $notice): bool
    {
        $retried = DB::transaction(function () use ($recovery, $notice) {
            $current = AccountRecoveryRequest::whereKey($recovery->id)->lockForUpdate()->first();

            if (! $current->canRetry($notice) || ($notice === 'reset' && ! $current->oldUser->mustResetPassword())) {
                return null;
            }

            $current->update([$current->noticeColumn($notice) => (string) Str::uuid()]);

            AuditLog::record('account.recovery_notice_retried', $current, ['reference' => $current->reference, 'notice' => $notice]);

            return $current;
        });

        if (! $retried) {
            return false;
        }

        return $notice === 'reset' ? $this->sendReset($retried) : $this->dispatch($retried, $notice);
    }

    /**
     * The old password stops working now. Its hash is kept only to refuse reuse, and the person
     * sets a new one from the emailed link. A Notary waits for fresh approval.
     *
     * A number can be reissued while an account is archived, so the phone must be proven again
     * unless this request proved that same number by genuine SMS. Otherwise whoever holds the
     * phone now could reset the password by SMS and take the account.
     */
    private function restoreOriginal(User $admin, User $old, AccountDeletion $deletion, AccountRecoveryRequest $recovery): void
    {
        $this->deletions->returnIdentity($old, $deletion);

        // Both proofs, or the phone starts unproven: SMS reset must never be the way in for
        // someone who only showed they hold the number.
        $phoneProven = $recovery->mobile_proof === AccountRecoveryRequest::PROOF_GENUINE_SMS
            && $recovery->verified_mobile === $deletion->mobile
            && $recovery->email_proof !== AccountRecoveryRequest::PROOF_NONE;

        // The same lock-down as a plain restore: see AccountDeletionService::requireFreshProof().
        $this->deletions->requireFreshProof($old, $phoneProven);
        User::whereKey($old->id)->update(['previous_account_choice' => User::CHOICE_NONE]);

        $deletion->update(['restored_by' => $admin->id, 'restored_at' => now(), 'restore_notification_id' => (string) Str::uuid()]);

        AuditLog::record('admin.user_restored', $old, ['reference' => $deletion->reference, 'role' => $deletion->role, 'via' => 'recovery']);
    }

    /**
     * Takes the rows before anything is read. On MariaDB the first plain read fixes what a
     * transaction can see, so reading first would hide what changed during a wait for a lock. Each
     * lookup goes through a unique index: it takes only that row, or the gap where it would go,
     * which holds back a registration of that identity. A search on LOWER(email) cannot use the
     * index and would lock every account, stalling every sign-up.
     */
    private function lockIdentity(AccountRecoveryRequest $recovery, AccountDeletion $deletion): void
    {
        User::where('email', $deletion->email)->lockForUpdate()->get();

        if ($deletion->mobile) {
            User::where('mobile', $deletion->mobile)->lockForUpdate()->get();
        }

        User::whereIn('id', [$recovery->old_user_id, $recovery->requester_id])->orderBy('id')->lockForUpdate()->get();
    }

    /** The requester is still the person who passed the checks, on the same address and number. */
    private function stillProven(User $requester, AccountRecoveryRequest $recovery): bool
    {
        return Str::lower($requester->email) === Str::lower($recovery->verified_email) && $requester->mobile === $recovery->verified_mobile;
    }

    /** A fresh link, to the registered address only. Forgot Password works if this one is lost. */
    private function sendReset(AccountRecoveryRequest $recovery): bool
    {
        try {
            $user = $recovery->oldUser()->first();
            $notification = new ResetPasswordNotification(Password::broker()->createToken($user));
            $notification->id = $recovery->reset_notification_id;

            $user->notify($notification);

            return true;
        } catch (Throwable $e) {
            // The account stays safe. The status shows Not queued, with a retry.
            report($e);

            return false;
        }
    }

    private function hasOpenWork(User $user): bool
    {
        return $user->assignedRequests()->whereIn('status', [NotaryRequest::STATUS_ASSIGNED, NotaryRequest::STATUS_CORRECTION, NotaryRequest::STATUS_APPROVED])->exists()
            || $user->requests()->whereIn('status', [NotaryRequest::STATUS_PENDING, NotaryRequest::STATUS_ASSIGNED, NotaryRequest::STATUS_CORRECTION, NotaryRequest::STATUS_APPROVED])->exists();
    }

    private function isBlank(User $user): bool
    {
        return ! NotaryRequest::withTrashed()->where('user_id', $user->id)->exists()
            && ! ServiceDraft::where('user_id', $user->id)->exists()
            && ! SupportTicket::where('user_id', $user->id)->exists()
            && ! Appointment::where('user_id', $user->id)->exists();
    }

    private function dispatch(AccountRecoveryRequest $recovery, string $notice): bool
    {
        $id = (string) $recovery->{$recovery->noticeColumn($notice)};
        $recipient = $notice === 'alert' ? $recovery->oldUser : $recovery->requester;

        try {
            $recipient->notify(new AccountRecoveryNotification($recovery->id, $notice, $id));

            return true;
        } catch (Throwable $e) {
            // The decision stands. The missing delivery record shows as Not queued, with a retry.
            report($e);

            return false;
        }
    }
}
