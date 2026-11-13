<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files attached to support tickets. Only PDF, JPG and PNG, checked by their content ("mimes")
 * and by their name ("extensions"), at most 3 files of 5 MB per message. Files go to the private
 * disk under a random name; the original name is kept only as text for display.
 */
class SupportAttachments
{
    /** Validation rules to merge into a ticket or reply form. Empty before the migration. */
    public function rules(): array
    {
        if (! SupportTicketAttachment::supported()) {
            return ['attachments' => ['prohibited']];
        }

        $formats = implode(',', SupportTicketAttachment::FORMATS);

        return [
            'attachments'   => ['nullable', 'array', 'max:'.SupportTicketAttachment::MAX_FILES],
            'attachments.*' => ['file', 'max:'.SupportTicketAttachment::MAX_KB, "mimes:{$formats}", "extensions:{$formats}"],
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.prohibited' => 'Attachments are not available yet.',
            'attachments.max'        => 'Attach at most '.SupportTicketAttachment::MAX_FILES.' files.',
            'attachments.*.max'      => 'Each file must be 5 MB or smaller.',
            'attachments.*.mimes'    => 'Attach PDF, JPG or PNG files only.',
            'attachments.*.extensions' => 'Attach PDF, JPG or PNG files only.',
        ];
    }

    /**
     * Run $work inside the caller's transaction with the files already stored. If anything
     * fails, the stored files are removed again, so no orphan file outlives a failed save.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function guard(array $files, Closure $work): mixed
    {
        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = [$file, $file->store('support', Document::PRIVATE_DISK)];
            }

            return $work($stored);
        } catch (Throwable $e) {
            foreach ($stored as [, $path]) {
                Storage::disk(Document::PRIVATE_DISK)->delete($path);
            }

            throw $e;
        }
    }

    /** @param  array<int, array{0: UploadedFile, 1: string}>  $stored */
    public function record(SupportTicket $ticket, SupportTicketMessage $message, User $user, array $stored): int
    {
        foreach ($stored as [$file, $path]) {
            $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string) $file->getClientOriginalName())) ?: 'attachment';

            SupportTicketAttachment::create([
                'ticket_id'     => $ticket->id,
                'message_id'    => $message->id,
                'user_id'       => $user->id,
                'original_name' => Str::limit($name, 190, ''),
                'path'          => $path,
                // From the stored bytes, never from what the browser claimed.
                'mime_type'     => Storage::disk(Document::PRIVATE_DISK)->mimeType($path) ?: 'application/octet-stream',
                'size'          => (int) $file->getSize(),
                'sha256'        => hash_file('sha256', Storage::disk(Document::PRIVATE_DISK)->path($path)),
            ]);
        }

        return count($stored);
    }
}
