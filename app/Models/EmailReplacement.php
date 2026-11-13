<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A proposed new email address for a restored account whose owner lost the old mailbox.
 * See App\Services\EmailReplacementService. The link token is stored only as a hash.
 */
class EmailReplacement extends Model
{
    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** How long the link to the new address works. */
    public const LINK_HOURS = 24;

    /** How the administrator checked the person's identity, outside the system. */
    public const CHECKS = ['video_call', 'in_person', 'phone_call', 'other'];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public static function supported(): bool
    {
        return Schema::hasTable('email_replacements');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The link stops working at expires_at exactly, in the application's timezone. */
    public function isExpired(): bool
    {
        return $this->expires_at === null || now()->greaterThanOrEqualTo($this->expires_at);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
