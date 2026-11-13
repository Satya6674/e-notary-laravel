<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Support\LogMailRedactor;
use Symfony\Component\Mime\Email;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time code for a recovery request. Sent at once, not queued, as a queued
 * notification would store the code in the jobs table. It is never filled in on screen.
 */
class AccountRecoveryCodeNotification extends Notification
{
    use BrandedMail;

    public function __construct(public string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail(
            subject: 'Your '.config('app.name').' account recovery code',
            greeting: 'Hello,',
            intro: [
                'Your account recovery code is '.$this->code.'.',
                'It is valid for '.\App\Services\OtpService::ttl().' minutes. Do not share it with anyone.',
            ],
            outro: ['If you did not ask for this, you can ignore this email.'],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->code]));
    }
}
