<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Services\OtpService;
use App\Support\LogMailRedactor;
use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Forgot Password: the 6-digit code, sent to the account's registered email address whether the
 * person typed that address or the account's mobile number. No link: the code is entered on the
 * Forgot Password page. Sent at once (never queued), so no job ever holds the code; it was stored
 * hashed by OtpService and is redacted from any log copy.
 */
class PasswordResetCodeNotification extends Notification
{
    use BrandedMail;

    public function __construct(public string $code, public DateTimeInterface $expiresAt)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = OtpService::ttl();

        return $this->brandedMail(
            subject: 'Your '.config('app.name').' password reset code',
            greeting: 'Hello '.($notifiable->name ?? '').',',
            intro: [
                'We received a request to reset the password of your '.config('app.name').' account.',
                'Your verification code is '.$this->code.'. Enter it on the Forgot Password page within '.$minutes.' minutes. Do not share it with anyone; '.config('app.name').' staff will never ask for it.',
            ],
            outro: [
                'If you did not ask to reset your password, you can ignore this email. Your password stays the same.',
            ],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->code]));
    }
}
