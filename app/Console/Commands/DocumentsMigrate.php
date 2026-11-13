<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves uploaded documents from the web-served "public" disk to the private
 * "local" disk. Dry-run by default; nothing changes unless --apply is given.
 * Files are copied first and the public copy removed only after the private
 * copy is verified to exist with the same size.
 */
class DocumentsMigrate extends Command
{
    protected $signature = 'enotary:documents-migrate {--apply : Actually move the files (default is a dry run)}';

    protected $description = 'Move uploaded documents from the public disk to the private disk (dry run unless --apply)';

    public function handle(): int
    {
        $public  = Storage::disk(Document::LEGACY_DISK);
        $private = Storage::disk(Document::PRIVATE_DISK);
        $apply   = (bool) $this->option('apply');

        $toMove = [];
        $missing = [];
        $alreadyPrivate = 0;

        foreach (Document::query()->cursor() as $doc) {
            $path = $doc->original_file;

            if (! $path) {
                $missing[] = $doc->id;
                continue;
            }

            if ($private->exists($path)) {
                $alreadyPrivate++;
                continue;
            }

            if ($public->exists($path)) {
                $toMove[] = $doc;
            } else {
                $missing[] = $doc->id;
            }
        }

        $this->line(sprintf('Documents: %d already private, %d to move, %d with no file on either disk%s',
            $alreadyPrivate, count($toMove), count($missing), $missing ? ' (ids '.implode(',', $missing).')' : ''));

        if (! $apply) {
            $this->warn('Dry run. Re-run with --apply to move the files.');

            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($toMove as $doc) {
            $path = $doc->original_file;
            $stream = $public->readStream($path);

            if (! $stream || ! $private->writeStream($path, $stream)) {
                $this->error("Failed to copy document {$doc->id}; left in place.");
                continue;
            }
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($private->size($path) !== $public->size($path)) {
                $private->delete($path);
                $this->error("Size mismatch for document {$doc->id}; left in place.");
                continue;
            }

            $public->delete($path);
            $moved++;
        }

        $this->info("Moved {$moved} of ".count($toMove).' files to the private disk.');

        return self::SUCCESS;
    }
}
