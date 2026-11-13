<?php

namespace App\Console\Commands;

use App\Services\AccountRecoveryService;
use App\Services\EmailReplacementService;
use Illuminate\Console\Command;

/**
 * Daily housekeeping for time-limited requests: account recovery requests older than 30 days
 * are closed as expired, and lost-mailbox replacement links past their 24 hours are marked
 * expired. Both already stop working at their limit; this records it and frees the slot.
 * Idempotent. Deletes nothing, never deletes an account, and never touches audit entries.
 */
class ExpireStaleRequests extends Command
{
    protected $signature = 'enotary:expire-stale-requests';

    protected $description = 'Close recovery requests past 30 days and lost-mailbox links past 24 hours (idempotent, deletes nothing)';

    public function handle(AccountRecoveryService $recoveries, EmailReplacementService $replacements): int
    {
        $closed = $recoveries->expireOverdue();
        $expired = $replacements->expireOverdue();

        $this->info("Recovery requests closed as expired: {$closed}. Replacement links marked expired: {$expired}.");

        return self::SUCCESS;
    }
}
