<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TemporaryAccessGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Admin > User Verification Management: the one write path for an Admin's manual verification of an
 * email address or a mobile number, and for a time-limited login exception.
 *
 * - A manual check is recorded as method admin_manual, never as an OTP or SMS. A manual mobile check
 *   leaves mobile_verified_at unset, so it never unlocks SMS password reset or phone proof.
 * - Email and mobile stay independent: each action changes one purpose only.
 * - Temporary access never marks anything verified and never touches Notary approval.
 * - Each change, its verification_events row (or grant row) and its audit entry are written in one
 *   transaction under a row lock, and a failed history write rolls the change back.
 * - Nothing is sent: no email, no SMS. The account holder is not contacted by this service.
 *
 * Refused for the acting Admin's own account, for any Admin account, and for a deleted or
 * deactivated account. Every check is made again under the lock.
 */
class ManualVerificationService
{
    public const PURPOSE_EMAIL = VerificationLedger::PURPOSE_EMAIL;

    public const PURPOSE_MOBILE = VerificationLedger::PURPOSE_MOBILE;

    public const EMAIL_VERIFIED = 'admin.verification.email_manual';

    public const MOBILE_VERIFIED = 'admin.verification.mobile_manual';

    public const ACCESS_GRANTED = 'admin.temporary_access.granted';

    public const ACCESS_REVOKED = 'admin.temporary_access.revoked';

    /** Written by enotary:record-access-expiry, once per grant, with no actor. */
    public const ACCESS_EXPIRED = 'system.temporary_access.expired';

    /** Why a grant ended without an explicit revocation: meta "cause" of an ACCESS_REVOKED entry. */
    public const CAUSE_ACCOUNT_DELETED = 'account_deleted';

    public const CAUSE_ACCOUNT_DEACTIVATED = 'account_deactivated';

    /** How the Admin confirmed ownership. Keys are stored; values are lang keys. */
    public const EVIDENCE_METHODS = [
        'phone_call' => 'Phone call to the registered number',
        'in_person_id' => 'In-person check of photo identity',
        'video_call_id' => 'Video call with photo identity',
        'email_from_address' => 'Email received from the registered address',
        'signed_letter' => 'Signed letter or official document',
        'other_documented' => 'Other method, described in the reason',
    ];

    public static function supported(): bool
    {
        return VerificationLedger::manualSupported() && User::mobileManualSupported() && TemporaryAccessGrant::supported();
    }

    /** Why this Admin may not act on this account at all, or null when they may. A lang key. */
    public function refusal(User $actor, User $target): ?string
    {
        return match (true) {
            ! self::supported() => 'This feature needs a database update (migrations 000029 and 000030) that has not been applied yet.',
            ! $actor->isAdmin() || $actor->isDeactivated() => 'Only an active administrator can manage verification.',
            $actor->is($target) => 'You cannot verify or grant access to your own account.',
            $target->isAdmin() => 'Administrator accounts cannot be verified manually or given temporary access.',
            ! in_array($target->role, ['public', 'notary'], true) => 'Only Citizen and Notary accounts can be managed here.',
            $target->isDeleted() => 'This account is deleted. Restore it before managing its verification.',
            $target->isDeactivated() => 'This account is deactivated. Reactivate it before managing its verification.',
            default => null,
        };
    }

    /** Why this purpose cannot be verified manually now, or null. A lang key. */
    public function verifyRefusal(User $target, string $purpose): ?string
    {
        return match ($purpose) {
            self::PURPOSE_EMAIL => match (true) {
                blank($target->email) => 'This account has no email address to verify.',
                $target->hasVerifiedEmail() => 'This email address is already verified.',
                default => null,
            },
            self::PURPOSE_MOBILE => match (true) {
                blank($target->mobile) => 'This account has no mobile number to verify.',
                $target->hasVerifiedMobile() => 'This mobile number is already verified by SMS.',
                $target->mobileManualVerified() => 'This mobile number is already verified manually.',
                $target->mobileFallbackVerified() => 'The mobile step is already complete through the email fallback.',
                default => null,
            },
            default => 'Choose what to verify: the email address or the mobile number.',
        };
    }

    /** Why a temporary access grant cannot be made now, or null. A lang key. */
    public function grantRefusal(User $target): ?string
    {
        $target->forgetTemporaryAccess();

        return match (true) {
            $target->mustResetPassword() => 'This account must set a new password first, so temporary access would not help.',
            $target->nextVerificationRoute() === null => 'This account has no pending verification step, so it needs no temporary access.',
            $target->activeTemporaryAccess() !== null => 'This account already has active temporary access. Revoke it first to change it.',
            default => null,
        };
    }

    /** @throws AuthorizationException when a check made under the lock refuses; nothing is saved */
    public function verify(User $actor, User $target, string $purpose, string $reason, string $evidence): User
    {
        $this->checkEvidence($evidence);

        return DB::transaction(function () use ($actor, $target, $purpose, $reason, $evidence) {
            [$freshActor, $row] = $this->lock($actor, $target);

            if ($why = $this->verifyRefusal($row, $purpose)) {
                throw new AuthorizationException($why);
            }

            if ($purpose === self::PURPOSE_EMAIL) {
                $previous = $row->email_verified_at === null ? 'pending' : ($row->email_verification_method ?? 'signed_link');
                $new = User::METHOD_ADMIN_MANUAL;
                $row->forceFill(['email_verified_at' => now(), 'email_verification_method' => User::METHOD_ADMIN_MANUAL]);
            } else {
                $previous = $row->mobile_verification_status ?? 'pending';
                $new = User::STATUS_ADMIN_VERIFIED;
                // mobile_verified_at stays unset: this is not SMS proof.
                $row->forceFill([
                    'mobile_verification_method' => User::METHOD_ADMIN_MANUAL,
                    'mobile_verification_status' => User::STATUS_ADMIN_VERIFIED,
                    'mobile_simulated_at' => null,
                    'mobile_manual_verified_at' => now(),
                    'mobile_manual_number' => $row->mobile,
                ] + (User::mobileFallbackSupported() ? ['mobile_sms_status' => User::SMS_NOT_SENT] : []));
            }

            $row->save();

            app(VerificationLedger::class)->recordManual($row, $purpose, [
                'actor_id' => $freshActor->id,
                'reason' => $reason,
                'evidence_method' => $evidence,
                'previous_status' => $previous,
                'new_status' => $new,
            ]);

            AuditLog::recordOrFail($purpose === self::PURPOSE_EMAIL ? self::EMAIL_VERIFIED : self::MOBILE_VERIFIED, $row, [
                'purpose' => $purpose,
                'method' => User::METHOD_ADMIN_MANUAL,
                'evidence_method' => $evidence,
                'reason' => $reason,
                'previous_status' => $previous,
                'new_status' => $new,
                'target' => $purpose === self::PURPOSE_EMAIL ? DeliveryLog::maskEmail($row->email) : SmsService::mask((string) $row->mobile),
            ], $freshActor->id);

            return $row;
        }, 3);
    }

    /** @throws AuthorizationException when a check made under the lock refuses; nothing is saved */
    public function grant(User $actor, User $target, int $hours, string $reason, string $evidence): TemporaryAccessGrant
    {
        $this->checkEvidence($evidence);

        if ($hours < TemporaryAccessGrant::MIN_HOURS || $hours > TemporaryAccessGrant::MAX_HOURS) {
            throw new AuthorizationException('Temporary access lasts between 1 and 72 hours.');
        }

        return DB::transaction(function () use ($actor, $target, $hours, $reason, $evidence) {
            [$freshActor, $row] = $this->lock($actor, $target);

            if ($why = $this->grantRefusal($row)) {
                throw new AuthorizationException($why);
            }

            $grant = TemporaryAccessGrant::create([
                'user_id' => $row->id,
                'granted_by' => $freshActor->id,
                'reason' => $reason,
                'evidence_method' => $evidence,
                'starts_at' => now(),
                'expires_at' => now()->addHours($hours),
            ]);

            AuditLog::recordOrFail(self::ACCESS_GRANTED, $row, [
                'grant_id' => $grant->id,
                'hours' => $hours,
                'expires_at' => $grant->expires_at->toIso8601String(),
                'evidence_method' => $evidence,
                'reason' => $reason,
                'pending_step' => $row->nextVerificationRoute(),
            ], $freshActor->id);

            $target->forgetTemporaryAccess();

            return $grant;
        }, 3);
    }

    /** @throws AuthorizationException when the grant is not active any more; nothing is saved */
    public function revoke(User $actor, User $target, TemporaryAccessGrant $grant, string $reason): TemporaryAccessGrant
    {
        return DB::transaction(function () use ($actor, $target, $grant, $reason) {
            [$freshActor, $row] = $this->lock($actor, $target, forRevoke: true);

            $fresh = TemporaryAccessGrant::whereKey($grant->id)->where('user_id', $row->id)->lockForUpdate()->first();

            if (! $fresh) {
                throw new AuthorizationException('This temporary access record does not belong to this account.');
            }

            if ($fresh->status() === TemporaryAccessGrant::STATUS_REVOKED) {
                throw new AuthorizationException('This temporary access was already revoked.');
            }

            if ($fresh->status() === TemporaryAccessGrant::STATUS_EXPIRED) {
                throw new AuthorizationException('This temporary access has already expired. Nothing to revoke.');
            }

            $fresh->forceFill(['revoked_at' => now(), 'revoked_by' => $freshActor->id, 'revoke_reason' => $reason])->save();

            AuditLog::recordOrFail(self::ACCESS_REVOKED, $row, [
                'grant_id' => $fresh->id,
                'reason' => $reason,
                'was_expiring_at' => $fresh->expires_at->toIso8601String(),
            ], $freshActor->id);

            $target->forgetTemporaryAccess();

            return $fresh;
        }, 3);
    }

    /**
     * Ends every grant still open (active or not yet started) on an account that is being deleted or
     * deactivated, so it cannot come back to life after a restore or reactivation within its window.
     * Recorded like a revocation, by the Admin who closed the account, with the cause in the audit
     * entry. Called inside the caller's transaction; a failed audit write rolls it all back.
     *
     * @return int how many grants were ended
     */
    public function endAccessFor(User $target, int $actorId, string $cause): int
    {
        if (! TemporaryAccessGrant::supported()) {
            return 0;
        }

        $open = TemporaryAccessGrant::where('user_id', $target->id)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->lockForUpdate()->get();

        foreach ($open as $grant) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $actorId, 'revoke_reason' => $cause])->save();

            AuditLog::recordOrFail(self::ACCESS_REVOKED, $target, [
                'grant_id' => $grant->id,
                'cause' => $cause,
                'was_expiring_at' => $grant->expires_at->toIso8601String(),
            ], $actorId);
        }

        $target->forgetTemporaryAccess();

        return $open->count();
    }

    /**
     * Writes one ACCESS_EXPIRED audit entry for each grant that ran to its expiry without being
     * revoked and has none yet. Idempotent: the grant is the entry's subject, so a second run finds it.
     * Changes no grant (they are immutable) and no account.
     *
     * @return int how many entries were written
     */
    public function recordExpiries(int $limit = 500): int
    {
        if (! TemporaryAccessGrant::supported()) {
            return 0;
        }

        $recorded = AuditLog::where('action', self::ACCESS_EXPIRED)->where('model_type', 'TemporaryAccessGrant')->select('model_id');

        $expired = TemporaryAccessGrant::whereNull('revoked_at')->where('expires_at', '<=', now())
            ->whereNotIn('id', $recorded)->orderBy('id')->limit($limit)->get();

        foreach ($expired as $grant) {
            AuditLog::recordOrFail(self::ACCESS_EXPIRED, $grant, [
                'user_id' => $grant->user_id,
                'granted_by' => $grant->granted_by,
                'expired_at' => $grant->expires_at->toIso8601String(),
            ], null);
        }

        return $expired->count();
    }

    /**
     * Locks the acting Admin and the account (id order) and checks both again.
     * A revocation is allowed on a deactivated or deleted account, as it only takes access away.
     *
     * @return array{0: User, 1: User}
     */
    private function lock(User $actor, User $target, bool $forRevoke = false): array
    {
        User::whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get();

        $freshActor = User::whereKey($actor->id)->first();
        $row = User::whereKey($target->id)->first();

        if (! $freshActor || ! $row) {
            throw new AuthorizationException('This account no longer exists.');
        }

        $why = $this->refusal($freshActor, $row);

        if ($forRevoke && in_array($why, [
            'This account is deleted. Restore it before managing its verification.',
            'This account is deactivated. Reactivate it before managing its verification.',
        ], true)) {
            $why = null;
        }

        if ($why) {
            throw new AuthorizationException($why);
        }

        return [$freshActor, $row];
    }

    private function checkEvidence(string $evidence): void
    {
        if (! array_key_exists($evidence, self::EVIDENCE_METHODS)) {
            throw new AuthorizationException('Choose how ownership was confirmed.');
        }
    }
}
