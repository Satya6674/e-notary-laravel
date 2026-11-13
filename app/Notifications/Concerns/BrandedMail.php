<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds a MailMessage that renders through the branded E-Notary templates
 * (HTML + plain-text) instead of Laravel's default markdown layout.
 */
trait BrandedMail
{
    /**
     * @param  string[]  $intro
     * @param  string[]  $outro
     */
    protected function brandedMail(
        string $subject,
        string $greeting,
        array $intro,
        ?string $actionText = null,
        ?string $actionUrl = null,
        array $outro = [],
        ?string $reference = null,
    ): MailMessage {
        return (new MailMessage)
            ->subject($subject)
            ->view(['emails.notification', 'emails.notification-text'], [
                'subject'    => $subject,
                'greeting'   => $greeting,
                'introLines' => $intro,
                'actionText' => $actionText,
                'actionUrl'  => $actionUrl,
                'outroLines' => $outro,
                'reference'  => $reference,
            ]);
    }
}
