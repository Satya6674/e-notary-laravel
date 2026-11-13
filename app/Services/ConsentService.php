<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Consent to the Terms of Use and the Privacy Policy. Each acceptance is an append-only audit
 * entry holding both versions, the time, the address and where it was given, so the full
 * history is kept without a table of its own.
 */
class ConsentService
{
    public const ACTION = 'privacy.consent_given';

    public function versions(): array
    {
        return ['terms_version' => (string) config('privacy.terms_version'), 'privacy_version' => (string) config('privacy.privacy_version')];
    }

    /**
     * Records consent. Throws if it cannot be written, so a sign-up inside a transaction is
     * rolled back rather than kept with no record of what was accepted.
     */
    public function record(User $user, string $source): AuditLog
    {
        return AuditLog::recordOrFail(self::ACTION, $user, $this->versions() + ['source' => $source], $user->id);
    }

    /** Every acceptance by this person, newest first. */
    public function history(User $user): Collection
    {
        return AuditLog::where('action', self::ACTION)->where('model_type', 'User')->where('model_id', $user->id)
            ->latest('id')->get();
    }

    /** True when the latest acceptance covers the versions in force now. */
    public function isCurrent(User $user): bool
    {
        $latest = $this->history($user)->first();

        return $latest !== null
            && ($latest->meta['terms_version'] ?? null) === $this->versions()['terms_version']
            && ($latest->meta['privacy_version'] ?? null) === $this->versions()['privacy_version'];
    }
}
