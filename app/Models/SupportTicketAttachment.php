<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A file on a support ticket message. Needs migration 2026_11_04_000027. */
class SupportTicketAttachment extends Model
{
    /** Formats people may attach: checked by content and by extension. Office files are not accepted. */
    public const FORMATS = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_KB = 5120;

    public const MAX_FILES = 3;

    protected $guarded = [];

    private static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ??= Schema::hasTable('support_ticket_attachments');
    }

    public static function forgetSchemaState(): void
    {
        self::$supported = null;
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function message()
    {
        return $this->belongsTo(SupportTicketMessage::class, 'message_id');
    }

    /** A safe name for the download: letters, digits and dashes, with the stored extension. */
    public function downloadName(): string
    {
        $ext = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($this->original_name, PATHINFO_FILENAME)) ?: 'attachment';

        return Str::limit($base, 80, '').'.'.$ext;
    }

    /** Always a download, never shown in the page, and refused if the file changed since upload. */
    public function downloadResponse(): ?StreamedResponse
    {
        $disk = Storage::disk(Document::PRIVATE_DISK);

        if (! $disk->exists($this->path) || ! hash_equals($this->sha256, (string) hash_file('sha256', $disk->path($this->path)))) {
            return null;
        }

        return $disk->download($this->path, $this->downloadName(), [
            'Content-Type'            => $this->mime_type,
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control'           => 'private, no-store',
        ]);
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
