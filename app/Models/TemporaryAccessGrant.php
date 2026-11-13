<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * A time-limited login exception granted by an Admin (migration 000030). It lets an account past the
 * pending verification pages to a short list of routes (App\Http\Middleware\RestrictTemporaryAccess).
 * It never marks an email or a number as verified, and never touches Notary approval.
 *
 * Active means: not revoked, started, and expires_at still in the future. Expiry needs no write and
 * no scheduler: the clock is compared on every request, so access ends the moment it expires.
 *
 * A row is written once, and may change once more, to record its revocation. The model refuses any
 * other change, and so does the database trigger.
 */
class TemporaryAccessGrant extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_SCHEDULED = 'scheduled';

    public const MIN_HOURS = 1;

    public const MAX_HOURS = 72;

    public const DEFAULT_HOURS = 24;

    protected $table = 'temporary_access_grants';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    private static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ??= Schema::hasTable('temporary_access_grants');
    }

    public static function forgetSchemaState(): void
    {
        self::$supported = null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $grant) {
            $allowed = ['revoked_at', 'revoked_by', 'revoke_reason'];

            if ($grant->getOriginal('revoked_at') !== null || array_diff(array_keys($grant->getDirty()), $allowed) || $grant->revoked_at === null) {
                throw new LogicException('A temporary access record can only be revoked, once.');
            }
        });

        static::deleting(fn () => throw new LogicException('Temporary access records cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grantor()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where('expires_at', '>', now());
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at === null || $this->expires_at->lte(now()) => self::STATUS_EXPIRED,
            $this->starts_at !== null && $this->starts_at->gt(now()) => self::STATUS_SCHEDULED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function isActive(): bool
    {
        return $this->status() === self::STATUS_ACTIVE;
    }
}
