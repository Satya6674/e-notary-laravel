<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\EmailReplacement;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\EmailReplacementAlertNotification;
use App\Notifications\EmailReplacementLinkNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lost-mailbox recovery (owner decision, 2026-11-04). For a restored account still waiting for
 * its new password, whose owner can no longer read the registered address:
 *
 *  1. The person asks Support (a support ticket or a contact message).
 *  2. An administrator checks their identity outside the system, then proposes a new address with
 *     a reason and a short record of the check. The old address is told; a single-use link goes
 *     to the new one. Nothing about the account changes yet.
 *  3. Opening the link (and confirming) moves the account to the new address, marks it verified,
 *     and sends the password link there. The account stays locked until that password is set.
 *
 * Accounts are never merged, and no session, code or reset link of the old address carries over.
 * Every step is audited, locked and checked again; tokens appear nowhere but in the link.
 */
class EmailReplacementService
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(private AccountDeletionService $deletions)
    {
    }

    /**
     * @param  array{new_email: string, identity_check: string, identity_note: string, reason: string, support_ticket_id?: ?int, contact_message_id?: ?int}  $data
     *
     * @throws AuthorizationException when not allowed now, with its reason
     */
    public function start(User $admin, User $target, array $data): EmailReplacement
    {
        $newEmail = Str::lower(trim($data['new_email']));
        $token = Str::random(64);

        try {
            $replacement = DB::transaction(function () use ($admin, $target, $data, $newEmail, $token) {
                // Both accounts in id order, the order every account-changing step uses.
                User::whereIn('id', [$admin->id, $target->id])->orderBy('id')->lockForUpdate()->get();
                $actor = User::whereKey($admin->id)->lockForUpdate()->first();
                $account = User::whereKey($target->id)->lockForUpdate()->first();

                $this->checkStart($actor, $account, $newEmail, $data);
                $this->closeExpiredFor($account);

                if (EmailReplacement::where('open_key', $account->id)->exists()) {
                    throw new AuthorizationException('A new address is already waiting to be confirmed for this account. Cancel it first.');
                }

                $replacement = EmailReplacement::create([
                    'reference' => 'EMR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                    'user_id' => $account->id,
                    'requested_by' => $actor->id,
                    'support_ticket_id' => $data['support_ticket_id'] ?? null,
                    'contact_message_id' => $data['contact_message_id'] ?? null,
                    'old_email' => $account->email,
                    'new_email' => $newEmail,
                    'identity_check' => $data['identity_check'],
                    'identity_note' => trim($data['identity_note']),
                    'reason' => trim($data['reason']),
                    'token_hash' => EmailReplacement::hashToken($token),
                    'expires_at' => now()->addHours(EmailReplacement::LINK_HOURS),
                    'status' => EmailReplacement::PENDING,
                    'open_key' => $account->id,
                    'open_email' => $newEmail,
                    'verification_notification_id' => (string) Str::uuid(),
                    'alert_notification_id' => (string) Str::uuid(),
                ]);

                AuditLog::recordOrFail('admin.email_replacement_started', $account, [
                    'reference' => $replacement->reference,
                    'source' => $replacement->support_ticket_id ? 'support_ticket' : 'contact_message',
                    'source_id' => $replacement->support_ticket_id ?? $replacement->contact_message_id,
                    'identity_check' => $replacement->identity_check,
                ], $actor->id);

                return $replacement;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new AuthorizationException('That address is already proposed for another account, or a replacement is already waiting here. Nothing was changed.');
        }

        // After the commit: the link to the new address, and the alert to the old one.
        $this->send($replacement->new_email, new EmailReplacementLinkNotification($replacement->id, $token), $replacement->verification_notification_id);
        $this->send($replacement->old_email, new EmailReplacementAlertNotification($replacement->id), $replacement->alert_notification_id);

        return $replacement;
    }

    /**
     * Confirms through the emailed link. Returns OK, INVALID (wrong or unknown link), USED,
     * EXPIRED or UNAVAILABLE (the account or address no longer allows it). Refusals are audited.
     */
    public function confirm(int $replacementId, string $token): string
    {
        try {
            [$result, $replacement] = DB::transaction(function () use ($replacementId, $token) {
                $replacement = EmailReplacement::whereKey($replacementId)->lockForUpdate()->first();

                if (! $replacement || ! hash_equals($replacement->token_hash, EmailReplacement::hashToken($token))) {
                    return [self::INVALID, $replacement];
                }

                if (! $replacement->isPending()) {
                    return [self::USED, $replacement];
                }

                if ($replacement->isExpired()) {
                    $this->expire($replacement);

                    return [self::EXPIRED, $replacement];
                }

                $account = User::whereKey($replacement->user_id)->lockForUpdate()->first();

                // Checked again now: the account must still be the same locked, non-Admin account,
                // on the same address, and the new address must still be free.
                if (! $account || $account->isDeleted() || $account->isDeactivated() || ! $account->mustResetPassword()
                    || strcasecmp((string) $account->email, $replacement->old_email) !== 0 || $this->deletions->everAdmin($account)
                    || $this->addressTaken($replacement->new_email, $account->id)) {
                    return [self::UNAVAILABLE, $replacement];
                }

                $attributes = ['email' => $replacement->new_email, 'email_verified_at' => now()];
                if (User::verificationStatusSupported()) {
                    $attributes['email_verification_method'] = 'replacement_link';
                }
                User::whereKey($account->id)->update($attributes + ['remember_token' => Str::random(60)]);

                // Nothing of the old address carries over: its reset links and the account's sessions end.
                DB::table(config('auth.passwords.users.table'))->whereIn('email', [$replacement->old_email, $replacement->new_email])->delete();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
                }

                $replacement->update(['status' => EmailReplacement::VERIFIED, 'verified_at' => now(), 'open_key' => null, 'open_email' => null,
                    'reset_notification_id' => (string) Str::uuid()]);

                AuditLog::recordOrFail('account.email_replaced', $account, ['reference' => $replacement->reference], null);

                return [self::OK, $replacement];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The address was registered by someone else while confirming.
            [$result, $replacement] = [self::UNAVAILABLE, EmailReplacement::find($replacementId)];
        }

        if ($result !== self::OK) {
            AuditLog::record('account.email_replacement_refused', $replacement?->user, ['reference' => $replacement?->reference, 'result' => $result], null);

            return $result;
        }

        // After the commit, to the new address only. The account stays locked until it is used.
        $this->sendResetLink($replacement);

        return self::OK;
    }

    /** @throws AuthorizationException */
    public function cancel(User $admin, EmailReplacement $replacement, string $reason): void
    {
        DB::transaction(function () use ($admin, $replacement, $reason) {
            $actor = User::whereKey($admin->id)->lockForUpdate()->first();
            $current = EmailReplacement::whereKey($replacement->id)->lockForUpdate()->firstOrFail();

            if (! $actor?->isAdmin() || $actor->isDeactivated()) {
                throw new AuthorizationException('Only an active administrator can cancel this.');
            }

            if (! $current->isPending()) {
                throw new AuthorizationException('This replacement is no longer waiting, so it cannot be cancelled.');
            }

            $current->update(['status' => EmailReplacement::CANCELLED, 'open_key' => null, 'open_email' => null,
                'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => trim($reason)]);

            AuditLog::recordOrFail('admin.email_replacement_cancelled', $current->user, ['reference' => $current->reference], $actor->id);
        }, 3);
    }

    /** @throws AuthorizationException */
    private function checkStart(?User $actor, ?User $account, string $newEmail, array $data): void
    {
        $refuse = fn (string $message) => throw new AuthorizationException($message);

        if (! $actor || ! $actor->isAdmin() || $actor->isDeactivated()) {
            $refuse('Only an active administrator can replace an email address.');
        }

        if (! $account || $account->is($actor)) {
            $refuse('You cannot replace the address of your own account.');
        }

        if ($account->isDeleted()) {
            $refuse('Restore this account before replacing its address.');
        }

        if ($account->isDeactivated()) {
            $refuse('This account is deactivated. Reactivate it first if that was a mistake.');
        }

        // An administrator's address is never replaced this way: that would be a way to take it over.
        if ($this->deletions->everAdmin($account)) {
            $refuse('The address of a current or former administrator account cannot be replaced here.');
        }

        if (! $account->mustResetPassword()) {
            $refuse('Only a restored account that is still waiting for its new password can have its address replaced here.');
        }

        if (strcasecmp((string) $account->email, $newEmail) === 0) {
            $refuse('The new address is the same as the current one.');
        }

        if ($this->addressTaken($newEmail, $account->id)) {
            $refuse('Another account uses that address. Nothing was changed.');
        }

        $ticket = ! empty($data['support_ticket_id']) && SupportTicket::whereKey($data['support_ticket_id'])->exists();
        $message = ! empty($data['contact_message_id']) && ContactMessage::whereKey($data['contact_message_id'])->exists();

        if (! $ticket && ! $message) {
            $refuse('Link the support ticket or contact message the person sent.');
        }
    }

    /** Held by any other account row, archived ones with a kept identity included. */
    private function addressTaken(string $email, int $exceptUserId): bool
    {
        return User::whereKeyNot($exceptUserId)->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists();
    }

    /**
     * Scheduled task: mark pending replacements whose link has run out as EXPIRED, which frees
     * the account and the address for a new attempt. The link already stopped working at its
     * expiry time; this only tidies the record. Idempotent, and nothing is deleted.
     */
    public function expireOverdue(): int
    {
        if (! EmailReplacement::supported()) {
            return 0;
        }

        $expired = 0;

        foreach (EmailReplacement::where('status', EmailReplacement::PENDING)->where('expires_at', '<=', now())->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($id) {
                $replacement = EmailReplacement::whereKey($id)->lockForUpdate()->first();

                if (! $replacement || $replacement->status !== EmailReplacement::PENDING || ! $replacement->isExpired()) {
                    return 0;
                }

                $this->expire($replacement);

                return 1;
            }, 3);
        }

        return $expired;
    }

    private function closeExpiredFor(User $account): void
    {
        $open = EmailReplacement::where('open_key', $account->id)->lockForUpdate()->first();

        if ($open && $open->isExpired()) {
            $this->expire($open);
        }
    }

    private function expire(EmailReplacement $replacement): void
    {
        $replacement->update(['status' => EmailReplacement::EXPIRED, 'open_key' => null, 'open_email' => null, 'closed_at' => now()]);

        AuditLog::record('account.email_replacement_expired', $replacement->user, ['reference' => $replacement->reference], null);
    }

    private function sendResetLink(EmailReplacement $replacement): void
    {
        try {
            $user = User::findOrFail($replacement->user_id);
            $notification = new ResetPasswordNotification(Password::broker()->createToken($user));
            $notification->id = $replacement->reset_notification_id;
            $user->notify($notification);
        } catch (Throwable $e) {
            // The address is confirmed; Forgot Password by email works from it as well.
            report($e);
        }
    }

    private function send(string $address, object $notification, string $id): void
    {
        try {
            $notification->id = $id;
            Notification::route('mail', $address)->notify($notification);
        } catch (Throwable $e) {
            // Recorded as not queued; the administrator can cancel and start again.
            report($e);
        }
    }
}
