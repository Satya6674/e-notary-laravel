<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\LogMailRedactor;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mobile-step code while SMS is unavailable, sent to the account's own verified address.
 * It says plainly that no SMS was sent and that the code does not prove the phone by SMS.
 *
 * The code was generated and stored hashed by OtpService before this was queued; the queued job
 * is encrypted and the code is redacted from any log copy. A retry after expiry sends no code.
 */
class MobileEmailFallbackCodeNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $code, public string $mobile, public DateTimeInterface $expiresAt)
    {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $usable = now()->lessThan($this->expiresAt);
        $number = SmsService::mask($this->mobile);

        $intro = ['SMS delivery is temporarily unavailable, so the code for your mobile verification step was sent to this email address instead. No text message was sent to '.$number.'.'];
        $intro[] = $usable
            ? 'Your mobile verification code is '.$this->code.'. Enter it on the mobile verification page within '.OtpService::ttl().' minutes. Do not share it with anyone.'
            : 'The code in this message expired before it could be sent. Please request a new code on the mobile verification page.';
        $intro[] = 'Your account will show the mobile step as "Verified via Email Fallback". It does not mean the number was confirmed by SMS.';

        return $this->brandedMail(
            subject: 'Your '.config('app.name').' mobile verification code (sent by email)',
            greeting: 'Hello '.($notifiable->name ?? '').',',
            intro: $intro,
            outro: ['If you did not ask for this, you can ignore this email and your account stays as it is.'],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->code]));
    }
}
