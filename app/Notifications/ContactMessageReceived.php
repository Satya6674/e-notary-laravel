<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the configured admin address (config mail.admin_address) when a
 * contact-form message is stored. The sender's address is shown as text only;
 * it is never used as a recipient or reply-to automatically.
 */
class ContactMessageReceived extends Notification implements ShouldQueue
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
            subject: config('app.name').': new contact message #'.$this->message->id,
            greeting: 'Hello admin,',
            intro: [
                'A new message was submitted through the website contact form.',
                'From: '.$this->message->name.' <'.$this->message->email.'>',
                'Subject: '.($this->message->subject ?: '(none)'),
                'Message: '.$this->message->message,
            ],
            actionText: 'Open admin messages',
            actionUrl: route('admin.messages.index'),
        );
    }
}
