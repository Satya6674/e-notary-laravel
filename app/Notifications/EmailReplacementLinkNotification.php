<?php

namespace App\Notifications;

use App\Models\EmailReplacement;
use App\Notifications\Concerns\BrandedMail;
use App\Support\LogMailRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single-use link that confirms a proposed new address. The token travels only inside this
 * email: it is encrypted while queued, removed from any log copy, and stored only as a hash.
 */
class EmailReplacementLinkNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $replacementId, public string $token)
    {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replacement = EmailReplacement::findOrFail($this->replacementId);

        return $this->brandedMail(
            subject: 'Confirm your new '.config('app.name').' email address',
            greeting: 'Hello,',
            intro: [
                'Our support team was asked to move an '.config('app.name').' account to this email address, after checking the account holder\'s identity.',
                'Nothing changes until you confirm. Once you do, a separate email will let you set a new password.',
            ],
            actionText: 'Confirm this email address',
            // On APP_URL, never the request's Host: the link carries a one-time token.
            actionUrl: \App\Support\AppUrl::using(fn () => url(route('email-replacement.show', ['replacement' => $replacement->id, 'token' => $this->token], false))),
            outro: [
                'This link works once and expires in '.EmailReplacement::LINK_HOURS.' hours.',
                'If you did not ask for this, ignore this email and nothing will change.',
            ],
            reference: $replacement->reference,
        )->withSymfonyMessage(fn () => LogMailRedactor::remember([$this->token]));
    }
}
