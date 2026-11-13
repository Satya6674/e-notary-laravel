<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * An uploaded file. "original_file" holds the path relative to the disk root
 * (e.g. documents/abc.pdf). Every document belongs to one NotaryRequest.
 *
 * Storage: uploads live only on the PRIVATE disk ("local", storage/app/private),
 * which the web server cannot reach. The helpers below never read the public
 * disk. A file left there by an old upload is not served until
 * `php artisan enotary:documents-migrate --apply` moves it to the private disk.
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    public const PRIVATE_DISK = 'local';

    // Only enotary:documents-migrate may read this disk.
    public const LEGACY_DISK  = 'public';

    public const PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png'];

    protected $table = 'documents';

    // Empty guarded list = every column can be mass-assigned with create()/update().
    protected $guarded = [];

    protected $casts = [
        'certified_at'  => 'datetime',
        'superseded_at' => 'datetime',
    ];

    // What a public certificate check can say. See certificateState().
    public const STATE_VALID        = 'valid';
    public const STATE_REVOKED      = 'revoked';
    public const STATE_SUPERSEDED   = 'superseded';
    public const STATE_UNVERIFIABLE = 'unverifiable';
    public const STATE_UNAVAILABLE  = 'unavailable';

    // Audit actions that prove an application was decided, whatever its status says now.
    public const DECISION_ACTIONS = ['request.approved', 'request.rejected', 'request.correction_requested', 'request.completed'];

    // Result of the file check, kept for this object, as one page can ask several times.
    private ?bool $intact = null;

    // The notary request this file was uploaded for (documents.request_id).
    public function request()
    {
        return $this->belongsTo(NotaryRequest::class, 'request_id');
    }

    /** The version this one replaced, if any. */
    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    /** For list pages: answers isProtectedRecord() without queries per row. */
    public function scopeWithRecordFlags($query)
    {
        return $query->withExists(['nextVersion as has_successor' => fn ($next) => $next->withTrashed()])
            ->addSelect(['was_decided' => AuditLog::query()->selectRaw('1')
                ->whereColumn('audit_logs.model_id', 'documents.request_id')
                ->where('audit_logs.model_type', 'NotaryRequest')
                ->whereIn('audit_logs.action', self::DECISION_ACTIONS)
                ->limit(1)]);
    }

    /**
     * A status can move on, but a decision stays made. Two independent sources answer
     * this: decided_at, which is never cleared, and the append-only audit log, which
     * can miss a row because an audit failure never stops the action being logged.
     */
    private function decisionRecorded(): bool
    {
        if ($this->request?->decided_at) {
            return true;
        }

        if (array_key_exists('was_decided', $this->attributes)) {
            return (bool) $this->attributes['was_decided'];
        }

        return NotaryRequest::withTrashed()->whereKey($this->request_id)->value('decided_at') !== null
            || AuditLog::where('model_type', 'NotaryRequest')->where('model_id', $this->request_id)
                ->whereIn('action', self::DECISION_ACTIONS)->exists();
    }

    /** Whether the service this application used requires this file. */
    public function isRequiredByService(): bool
    {
        return $this->requirement_key && in_array($this->requirement_key, $this->request?->requiredDocumentKeys() ?? [], true);
    }

    /** The version that replaced this one, if any. */
    public function nextVersion()
    {
        return $this->hasOne(self::class, 'replaces_document_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * Whether the stored bytes are still the bytes recorded at upload. A certificate
     * vouches for a file, so a missing or altered file must not pass.
     */
    public function fileMatchesRecord(): bool
    {
        if (! $this->file_hash) {
            return false;
        }

        // A folder at that path, or an unreadable file, is a file that cannot be confirmed.
        try {
            return $this->intact ??= $this->fileExists()
                && hash_equals($this->file_hash, (string) hash_file('sha256', Storage::disk(self::PRIVATE_DISK)->path($this->original_file)));
        } catch (Throwable) {
            return $this->intact = false;
        }
    }

    /**
     * The one answer to "is this certificate good?". Checked from the most final fact
     * down, so a replaced file reads as replaced whatever its application has done since.
     *
     *   unavailable:  the record or its application was removed.
     *   superseded:   a newer version replaced this file.
     *   revoked:      the application is no longer completed.
     *   unverifiable: the stored file is missing or does not match its fingerprint.
     */
    public function certificateState(): string
    {
        return match (true) {
            $this->trashed() || ! $this->request || $this->request->trashed() => self::STATE_UNAVAILABLE,
            ! $this->isCurrent() => self::STATE_SUPERSEDED,
            $this->request->status !== NotaryRequest::STATUS_COMPLETED || ! $this->certified_at => self::STATE_REVOKED,
            ! $this->fileMatchesRecord() => self::STATE_UNVERIFIABLE,
            default => self::STATE_VALID,
        };
    }

    /**
     * Part of the permanent record: a replaced version, a corrected version, one with a
     * successor, anything ever certified, or any file on an application that was ever
     * decided. These stay, whoever asks. This one rule covers citizens, administrators
     * and the purge command.
     */
    public function isProtectedRecord(): bool
    {
        return ! $this->isCurrent()
            || $this->verification_code !== null
            || $this->replaces_document_id !== null
            || in_array($this->request?->status, NotaryRequest::DECIDED_STATUSES, true)
            || ($this->has_successor ?? $this->nextVersion()->withTrashed()->exists())
            || $this->decisionRecorded();
    }

    /**
     * The word shown in a version history, or null for an ordinary current file.
     * Follows the certificate check, so a history can never disagree with /verify.
     */
    public function lifecycleStatus(bool $checkFile = false): ?string
    {
        if (! $this->isCurrent()) {
            return 'superseded';
        }

        if ($this->verification_code) {
            return match ($this->certificateState()) {
                self::STATE_VALID        => 'certified',
                self::STATE_UNVERIFIABLE => 'integrity_failed',
                default                  => 'revoked',
            };
        }

        if ($this->request?->status === NotaryRequest::STATUS_REJECTED) {
            return 'rejected';
        }

        // Off on list pages, as it reads every file. Detail pages warn the reviewer early.
        return $checkFile && ! $this->fileMatchesRecord() ? 'integrity_failed' : null;
    }

    /** The disk that currently holds the file, or null if it is missing. */
    public function storageDisk(): ?string
    {
        if (! $this->original_file) {
            return null;
        }

        // A stored path the disk refuses is a missing file, not a server error.
        try {
            return Storage::disk(self::PRIVATE_DISK)->exists($this->original_file) ? self::PRIVATE_DISK : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function fileExists(): bool
    {
        return $this->storageDisk() !== null;
    }

    /**
     * Whether a saved record names this path. After a failure, cleanup must only undo
     * work that really rolled back: a fault after the commit leaves the record in place.
     */
    public static function isOnRecord(string $path): bool
    {
        return self::withTrashed()->where('original_file', $path)->exists();
    }

    /** The public lookup address, always built from the configured application URL. */
    public function verificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/verify/'.$this->verification_code;
    }

    /** A local address on a document would not open for anyone else. */
    public function verificationUrlIsLocal(): bool
    {
        return in_array(parse_url((string) config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** Friendly download name based on the title, keeping the real extension. */
    public function downloadName(): string
    {
        $ext = strtolower(pathinfo((string) $this->original_file, PATHINFO_EXTENSION));
        $base = Str::slug((string) ($this->title ?: 'document')) ?: 'document';

        return $ext ? "{$base}.{$ext}" : $base;
    }

    /** Streamed attachment response, or null when the file is missing. */
    public function downloadResponse(): ?StreamedResponse
    {
        $disk = $this->storageDisk();

        if (! $disk) {
            return null;
        }

        // All three download routes end here, so this one line covers them.
        AuditLog::record('document.downloaded', $this, ['request_id' => $this->request_id]);

        return Storage::disk($disk)->download($this->original_file, $this->downloadName());
    }

    /**
     * Shown in the browser only for types it renders natively. The sandbox header
     * stops a crafted file running script in this site's origin.
     */
    public function previewResponse(): ?StreamedResponse
    {
        if (! $this->storageDisk() || ! in_array($this->detectedMime(), self::PREVIEWABLE, true)) {
            return null;
        }

        AuditLog::record('document.previewed', $this, ['request_id' => $this->request_id]);

        return Storage::disk(self::PRIVATE_DISK)->response($this->original_file, $this->downloadName(), [
            'Content-Type'            => $this->detectedMime(),
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; object-src 'self'",
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, no-store',
        ], 'inline');
    }

    public function isPreviewable(): bool
    {
        return $this->storageDisk() !== null && in_array($this->detectedMime(), self::PREVIEWABLE, true);
    }

    /** From the stored bytes, never from what the browser claimed at upload. */
    private function detectedMime(): ?string
    {
        return Storage::disk(self::PRIVATE_DISK)->mimeType($this->original_file) ?: null;
    }

    /** Remove the file from whichever disk holds it (no-op if already gone). */
    public function deleteFile(): void
    {
        // Two records can name one file after a double submit. The other one still needs it.
        $shared = self::withTrashed()->where('original_file', $this->original_file)->whereKeyNot($this->id)->exists();

        if (! $shared && $disk = $this->storageDisk()) {
            Storage::disk($disk)->delete($this->original_file);
        }
    }
}
