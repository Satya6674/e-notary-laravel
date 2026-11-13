<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Services\OtpService;
use App\Support\AppUrl;
use App\Support\LogMailRedactor;
use DateTimeInterface;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email-address verification: a 6-digit code to type on the verification page. The email carries no
 * link (approved 2026-11-08); the signed verification route still exists for links sent earlier.
 *
 * The code was generated and stored hashed by OtpService. The email is sent at once by
 * User::sendEmailVerification(), so no job holds it; should it ever be queued, the job is encrypted.
 * It is redacted from any log copy.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public ?string $code = null, public ?DateTimeInterface $codeExpiresAt = null)
    {
        $this->afterCommit = true;
    }

    /**
     * The 6-digit code only. No link: the code is entered on the verification page, which also works
     * when the email is read on another device. A code that expired before sending is not included.
     */
    public function toMail($notifiable): MailMessage
    {
        $code = $this->code && (! $this->codeExpiresAt || now()->lessThan($this->codeExpiresAt)) ? $this->code : null;

        $intro = ['Thank you for creating an '.config('app.name').' account. Please confirm this email address to complete your registration.'];
        $intro[] = $code
            ? 'Your verification code is '.$code.'. Enter it on the verification page within '.OtpService::ttl().' minutes. Do not share it with anyone; '.config('app.name').' staff will never ask for it.'
            : 'This code expired before it could be sent. Please request a new code on the verification page.';

        return $this->brandedMail(
            subject: 'Your '.config('app.name').' verification code',
            greeting: 'Hello '.$notifiable->name.',',
            intro: $intro,
            outro: [
                'If you did not create an account, you can ignore this email. No account will be activated without this code.',
            ],
        )->withSymfonyMessage(fn () => LogMailRedactor::remember(array_filter([$code])));
    }

    /** The framework's signed link, built and signed on APP_URL whatever Host the request carried. */
    protected function verificationUrl($notifiable)
    {
        return AppUrl::using(fn () => parent::verificationUrl($notifiable));
    }

}
