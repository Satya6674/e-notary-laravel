<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-off message used only by the "enotary:mail-test" artisan command
 * (CLI only, no web route) to confirm SMTP delivery.
 */
class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' SMTP test message');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'subject'    => config('app.name').' SMTP test message',
                'greeting'   => 'Hello,',
                'introLines' => [
                    'This is a test message sent from the '.config('app.name').' application to confirm that outgoing email is configured correctly.',
                    'Sent at '.now()->toDayDateTimeString().' ('.config('app.timezone').').',
                ],
                'actionText' => 'Open '.config('app.name'),
                'actionUrl'  => config('app.url'),
                'outroLines' => ['No action is required.'],
                'reference'  => null,
            ],
        );
    }
}
