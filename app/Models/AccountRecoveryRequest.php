<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A request to get a deleted account back. It never restores anything by itself: an
 * administrator decides, and until then the old account stays archived and hidden.
 */
class AccountRecoveryRequest extends Model
{
    public const PENDING = 'pending';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** "reset" is the password link. The restoration email lives on the deletion record. */
    public const NOTICES = ['requested', 'alert', 'approved', 'rejected', 'reset'];

    /** On the live site: the mailbox was proven by typing a code that was not filled in. */
    public const PROOF_EMAIL_CODE = 'emailed_code';

    /** The same steps away from the live site, where they establish nothing about ownership. */
    public const PROOF_EMAIL_LOCAL = 'emailed_code_local';

    public const PROOF_GENUINE_SMS = 'genuine_sms';

    /** The local email bridge. Never proof of a phone. */
    public const PROOF_SIMULATED = 'simulated_local';

    /** The local stand-in for the SMS provider. No phone was reached. */
    public const PROOF_MOCK_SMS = 'mock_sms';

    public const PROOF_NONE = 'none';

    public const ENV_LOCAL = 'local_development';

    public const ENV_PRODUCTION = 'production';

    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime'];

    /** Closed by an administrator because it was too old to approve. The person may ask again. */
    public const EXPIRED = 'expired';

    /** A request can be approved for this many days after it was made (owner decision, 2026-11-04). */
    public const MAX_AGE_DAYS = 30;

    /** The moment it stops being approvable: exactly MAX_AGE_DAYS after it was made, in the app's timezone. */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->setTimezone(config('app.timezone'))->addDays(self::MAX_AGE_DAYS);
    }

    /** Expired from that very moment on: a request made exactly 30 days ago can no longer be approved. */
    public function isExpired(): bool
    {
        return $this->expiresAt() !== null && now()->greaterThanOrEqualTo($this->expiresAt());
    }

    public function deletion()
    {
        return $this->belongsTo(AccountDeletion::class, 'account_deletion_id');
    }

    public function oldUser()
    {
        return $this->belongsTo(User::class, 'old_user_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::UNDER_REVIEW], true);
    }

    public function isLocal(): bool
    {
        return $this->environment === self::ENV_LOCAL;
    }

    public function noticeColumn(string $notice): string
    {
        return $notice.'_notification_id';
    }

    /** queued, sent, failed, blocked or not_queued, from the delivery record. */
    public function noticeStatus(string $notice): ?string
    {
        $id = $this->{$this->noticeColumn($notice)};

        if (! $id) {
            return null;
        }

        return match (MessageDelivery::where('notification_id', $id)->value('status')) {
            MessageDelivery::ACCEPTED => 'sent',
            MessageDelivery::FAILED => 'failed',
            MessageDelivery::BLOCKED => 'blocked',
            MessageDelivery::QUEUED, MessageDelivery::SENDING => 'queued',
            default => 'not_queued',
        };
    }

    public function canRetry(string $notice): bool
    {
        return in_array($this->noticeStatus($notice), ['failed', 'blocked', 'not_queued'], true);
    }
}
