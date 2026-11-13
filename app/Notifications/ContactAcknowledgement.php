<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Short acknowledgement to the person who used the contact form.
 * It is fixed text: it echoes nothing the visitor typed (not the message, subject or name), so the
 * form cannot be used to relay chosen text to an address of someone else's choosing. It names no
 * staff member or internal address. ContactController decides whether it is sent (abuse limits).
 */
class ContactAcknowledgement extends Notification implements ShouldQueue
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public ContactMessage $message)
    {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail(
            subject: config('app.name').': we received your message',
            greeting: 'Hello,',
            intro: [
                'Thank you for contacting '.config('app.name').'. Your message has been received and our team will reply as soon as possible.',
            ],
            reference: 'Message #'.$this->message->id,
        );
    }
}
