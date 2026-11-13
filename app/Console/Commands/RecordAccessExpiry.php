<?php

namespace App\Console\Commands;

use App\Services\ManualVerificationService;
use Illuminate\Console\Command;

/**
 * Hourly: one "system.temporary_access.expired" audit entry for each temporary access grant that
 * reached its expiry without being revoked. Access already ends at that moment, because the clock
 * is checked on every request; this only records it. Idempotent, changes no grant and no account,
 * and does nothing before migration 000030 has run.
 */
class RecordAccessExpiry extends Command
{
    protected $signature = 'enotary:record-access-expiry';

    protected $description = 'Record the expiry of temporary access grants in the audit log (idempotent, changes nothing else)';

    public function handle(ManualVerificationService $service): int
    {
        $this->info('Temporary access expiries recorded: '.$service->recordExpiries().'.');

        return self::SUCCESS;
    }
}
