<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A citizen's unsent application. "files" maps a requirement key to
 * {path, name, mime, size, hash}. The files are on the private disk under
 * documents/drafts, and move to documents/ when the application is submitted.
 */
class ServiceDraft extends Model
{
    public const FOLDER = 'documents/drafts';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'files'   => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function removeFile(string $key): void
    {
        $files = $this->files ?? [];

        if (isset($files[$key])) {
            Storage::disk(Document::PRIVATE_DISK)->delete($files[$key]['path']);
            unset($files[$key]);
            $this->update(['files' => $files]);
        }
    }

    /** Remove the draft and every file it still holds. */
    public function discard(): void
    {
        foreach ($this->files ?? [] as $file) {
            Storage::disk(Document::PRIVATE_DISK)->delete($file['path']);
        }

        $this->delete();
    }
}
