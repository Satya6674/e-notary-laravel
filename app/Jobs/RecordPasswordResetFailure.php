<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Services\PasswordResetCodes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

use function Illuminate\Support\defer;

/**
 * Audits a failed code at step 2 of Forgot Password, after the response.
 *
 * Every failure queues this, so the page does the same work whether or not an account matches.
 * Only a real account is recorded, never with the identifier or the code.
 */
class RecordPasswordResetFailure implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $identifier, public string $reason, public ?string $ip = null)
    {
    }

    public function handle(): void
    {
        AuditLog::$jobIp = $this->ip;

        try {
            if ($user = PasswordResetCodes::account($this->identifier)) {
                AuditLog::record('auth.otp_failed', $user, ['flow' => 'password_reset_email', 'via' => PasswordResetCodes::via($this->identifier), 'reason' => $this->reason], $user->id);
            }
        } finally {
            AuditLog::$jobIp = null;
        }
    }

    public static function afterResponseOrQueue(string $identifier, string $reason, ?string $ip = null): void
    {
        // On a synchronous queue, Laravel's defer() runs it once, after the response, and then forgets it.
        if (config('queue.default') === 'sync') {
            defer(fn () => dispatch_sync(new static($identifier, $reason, $ip)));

            return;
        }

        static::dispatch($identifier, $reason, $ip);
    }
}
