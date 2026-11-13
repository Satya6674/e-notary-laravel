<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Support\LogMailRedactor;
use Symfony\Component\Mime\Email;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code for the local email bridge. Deliberately not queued: a queued notification
 * would be stored in the jobs table with the code in it.
 */
class MobileOtpTestNotification extends Notification
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
            subject: 'Development test code for '.config('app.name'),
            greeting: isset($notifiable->name) ? 'Hello '.$notifiable->name.',' : 'Hello,',
            intro: [
                'Your development test code is '.$this->code.'.',
                'It is valid for '.\App\Services\OtpService::ttl().' minutes.',
                'This is a local test. No SMS was sent, and your mobile number is not verified by it.',
            ],
            outro: ['If you did not ask for this, you can ignore this email.'],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->code]));
    }
}
