<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Support\LogMailRedactor;
use Symfony\Component\Mime\Email;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset link built by Laravel's password broker. The token only ever
 * travels inside this email; it is never shown to the requester in the browser.
 *
 * Encrypted while it waits in the queue: the jobs table would otherwise hold a live
 * token that anyone able to read the database could use.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct($token)
    {
        parent::__construct($token);
        $this->afterCommit = true;
    }

    public function toMail($notifiable): MailMessage
    {
        // On APP_URL, never the request's Host: the link carries the reset token.
        $url = \App\Support\AppUrl::using(fn () => url(route('password.reset.token', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false)));

        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return $this->brandedMail(
            subject: 'Reset your '.config('app.name').' password',
            greeting: 'Hello '.$notifiable->name.',',
            intro: [
                'We received a request to reset the password for your account.',
            ],
            actionText: 'Reset password',
            actionUrl: $url,
            outro: [
                "This link expires in {$minutes} minutes and can be used once.",
                'If you did not request a password reset, you can ignore this email; your password will not change.',
            ],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->token]));
    }
}
