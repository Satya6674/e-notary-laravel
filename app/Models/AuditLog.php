<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * A record of an important action in the system, shown on the admin Audit Logs page.
 *
 * Write entries with AuditLog::record(). Never put passwords, OTPs, tokens or
 * file contents in $meta: store ids and the names of changed fields only.
 * Entries are append-only: the model refuses to edit or delete one.
 */
class AuditLog extends Model
{
    use HasFactory;

    // The table has created_at only: entries are never edited.
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    // Empty guarded list = every column can be mass-assigned with create()/update().
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit entries cannot be deleted.'));
    }

    // The user who performed the action (may be empty for system actions).
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add an entry. $userId defaults to the logged-in user; pass it when the
     * session no longer holds the actor (logout). A logging failure is reported
     * but never stops the action being logged.
     */
    public static function record(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): void
    {
        // One insert, attempted once. Nothing is retried after a failure, so a failure raised
        // after the row was written (by a listener, say) can never produce a second row.
        try {
            static::create(static::attributesFor($action, $subject, $meta, $userId));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Add an entry the action depends on: one insert, and a failure is thrown, so a caller inside
     * a transaction rolls its change back instead of keeping it with no record. Used where the
     * entry is itself evidence the system reads later, such as an Admin role change.
     */
    public static function recordOrFail(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): self
    {
        return static::create(static::attributesFor($action, $subject, $meta, $userId));
    }

    /** Set by a queued job for the duration of its work: the IP of the request that queued it. */
    public static ?string $jobIp = null;

    /** The columns of one entry. Builds values only; writes nothing. */
    private static function attributesFor(string $action, ?Model $subject, array $meta, ?int $userId): array
    {
        $http = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return [
            'user_id'    => $userId ?? Auth::id(),
            'action'     => $action,
            'model_type' => $subject ? class_basename($subject) : null,
            'model_id'   => $subject?->getKey(),
            'meta'       => $meta ?: null,
            // A queued job has no request; it passes the requester's IP through $jobIp.
            'ip_address' => static::$jobIp ?? $http?->ip(),
            'device'     => $http ? Str::limit((string) $http->userAgent(), 255, '') : null,
        ];
    }
}
