<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One deletion of one account, kept after a restore. name, email and role are the values
 * at the time of deletion, so the notices and history stay correct.
 */
class AccountDeletion extends Model
{
    public const NOTICE_DELETION = 'deletion';

    public const NOTICE_RESTORE = 'restore';

    protected $guarded = [];

    protected $casts = [
        'was_deactivated' => 'boolean',
        'notice_suppressed' => 'boolean',
        'deleted_at' => 'datetime',
        'restored_at' => 'datetime',
        'identity_released_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy()
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function isActive(): bool
    {
        return $this->restored_at === null;
    }

    public function noticeColumn(string $notice): string
    {
        return $notice === self::NOTICE_RESTORE ? 'restore_notification_id' : 'deletion_notification_id';
    }

    /** What the delivery record says now: queued, sent, failed, blocked, not_queued or suppressed. */
    public function noticeStatus(string $notice): string
    {
        // A new account replaced by its owner's recovered one. The recovery emails cover it.
        if ($notice === self::NOTICE_DELETION && $this->notice_suppressed) {
            return 'suppressed';
        }

        $id = $this->{$this->noticeColumn($notice)};

        // No delivery record means the queue never took it, so nothing is on its way.
        return match ($id ? MessageDelivery::where('notification_id', $id)->value('status') : null) {
            MessageDelivery::ACCEPTED => 'sent',
            MessageDelivery::FAILED => 'failed',
            MessageDelivery::BLOCKED => 'blocked',
            MessageDelivery::QUEUED, MessageDelivery::SENDING => 'queued',
            default => 'not_queued',
        };
    }

    /** A notice may go again only once nothing is on its way, and only while it still applies. */
    public function canRetry(string $notice): bool
    {
        $applies = $notice === self::NOTICE_RESTORE ? $this->restored_at !== null : $this->isActive();

        return $applies && in_array($this->noticeStatus($notice), ['failed', 'blocked', 'not_queued'], true);
    }
}
