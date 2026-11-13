<?php

namespace App\Console\Commands;

use App\Models\ServiceDraft;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Removes unsent applications and their files. Submitted applications are never touched, and
 * neither is anything of an archived account, which is kept until a retention policy exists.
 */
class ClearStaleDrafts extends Command
{
    protected $signature = 'enotary:clear-stale-drafts {--days=14 : Age of the drafts to remove}';

    protected $description = 'Delete unsent application drafts and their files';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = 0;

        ServiceDraft::where('updated_at', '<', now()->subDays($days))->when(User::softDeleteSupported(), fn ($query) => $query->whereDoesntHave('user', fn ($owner) => $owner->whereNotNull('deleted_at')))->each(function (ServiceDraft $draft) use (&$count) {
            $draft->discard();
            $count++;
        });

        $this->info("Removed {$count} drafts older than {$days} days.");

        return self::SUCCESS;
    }
}
