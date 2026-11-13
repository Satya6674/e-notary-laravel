<?php

namespace App\Notifications;

use App\Models\EmailReplacement;
use App\Notifications\Concerns\BrandedMail;
use App\Services\DeliveryLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the account's current (old) address that a replacement was requested. The new address
 * is shown masked, and nothing here can be used to approve or stop the change by itself.
 */
class EmailReplacementAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $replacementId)
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
            subject: 'Security alert: a new email address was requested for your '.config('app.name').' account',
            greeting: 'Hello,',
            intro: [
                'Our support team was asked to move your account from this address to '.DeliveryLog::maskEmail($replacement->new_email).'.',
                'The change happens only if that new address confirms it within '.EmailReplacement::LINK_HOURS.' hours.',
                'If this was not you, contact support at once, quoting the reference below.',
            ],
            outro: ['You are receiving this because this address is registered on the account.'],
            reference: $replacement->reference,
        );
    }
}
