<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes documents that were deleted more than --days ago. Dry run by
 * default: nothing changes without --apply. Never touches audit logs, active records,
 * anything belonging to an approved or completed application, or the permanent record:
 * certified documents and any file in a version history.
 */
class PurgeExpiredData extends Command
{
    protected $signature = 'enotary:purge-expired {--days=365 : Retention period for removed documents} {--apply : Actually delete (default is a dry run)}';

    protected $description = 'Permanently delete removed documents past the retention period (dry run unless --apply)';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $documents = Document::onlyTrashed()->where('deleted_at', '<', $cutoff)
            ->whereDoesntHave('request', fn ($q) => $q->whereIn('status', ['approved', 'completed']))
            ->get()->reject(fn (Document $document) => $document->isProtectedRecord());

        $this->line("Removed documents older than {$days} days: ".$documents->count());

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to delete them.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            $document->deleteFile();
            AuditLog::record('document.purged', $document, ['request_id' => $document->request_id]);
            $document->forceDelete();
        }

        $this->info('Purged '.$documents->count().' documents.');

        return self::SUCCESS;
    }
}
