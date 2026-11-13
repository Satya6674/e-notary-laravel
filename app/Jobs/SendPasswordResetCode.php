<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\DeliveryLog;
use App\Services\OtpService;
use App\Services\PasswordResetCodes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

use function Illuminate\Support\defer;

/**
 * Step 1 of Forgot Password, done after the response.
 *
 * The page queues this for every well-formed email address or mobile number, registered or not, and
 * does nothing else that depends on the account, so neither its wording nor its timing says whether
 * an account matches. Here, out of sight, the account is looked up and, if it may reset, a code is
 * generated, stored hashed and emailed to its registered, verified address. Never by SMS.
 *
 * The job carries the identifier and IP only, encrypted at rest. No code exists until it runs.
 */
class SendPasswordResetCode implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** One try: a retry could send a second email. OtpService refuses one inside the cooldown anyway. */
    public int $tries = 1;

    public function __construct(public string $identifier, public ?string $ip)
    {
    }

    public function handle(OtpService $otp, DeliveryLog $deliveries): void
    {
        AuditLog::$jobIp = $this->ip;

        try {
            $this->run($otp, $deliveries);
        } finally {
            AuditLog::$jobIp = null;
        }
    }

    private function run(OtpService $otp, DeliveryLog $deliveries): void
    {
        $user = PasswordResetCodes::account($this->identifier);

        if (! $user) {
            return;
        }

        $via = PasswordResetCodes::via($this->identifier);

        if ($refusal = PasswordResetCodes::refusal($user, $via)) {
            AuditLog::record('auth.password_reset_refused', $user, ['via' => $via, 'reason' => $refusal], $user->id);

            return;
        }

        // Cooldown, hourly limit and lock-out are this flow's own.
        [$result, $code] = $otp->issuePasswordResetCode($user, $this->ip);

        if ($result !== OtpService::ISSUED) {
            AuditLog::record('auth.password_reset_code_refused', $user, ['via' => $via, 'reason' => $result], $user->id);

            return;
        }

        $delivery = $deliveries->sendNow($user, new PasswordResetCodeNotification($code, now()->addMinutes(OtpService::ttl())));

        // A code nobody received must not stay usable.
        if (in_array($delivery, [MessageDelivery::BLOCKED, MessageDelivery::FAILED], true)) {
            $otp->cancelEmailCode($user, OtpService::FLOW_PASSWORD_RESET_EMAIL);
        }

        AuditLog::record('auth.password_reset_code_issued', $user, ['via' => $via, 'delivery' => $delivery ?? 'unknown'], $user->id);
    }

    /**
     * Queues it, or on a synchronous queue runs it after the response has been sent, so the page
     * never waits for the lookup, the hashing or the mail server.
     */
    public static function afterResponseOrQueue(string $identifier, ?string $ip): void
    {
        // On a synchronous queue, Laravel's defer() runs it once, after the response, and then forgets it.
        if (config('queue.default') === 'sync') {
            defer(fn () => dispatch_sync(new static($identifier, $ip)));

            return;
        }

        static::dispatch($identifier, $ip);
    }
}
