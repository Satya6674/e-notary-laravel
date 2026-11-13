<?php

namespace App\Notifications;

use App\Models\AccountDeletion;
use App\Notifications\Concerns\AccountNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a person their account was deactivated by an administrator. */
class AccountDeletedNotification extends Notification implements ShouldQueue
{
    use AccountNotice;

    public function __construct(public int $deletionId, string $id)
    {
        $this->id = $id;
        $this->afterCommit = true;
    }

    private function column(): string
    {
        return 'deletion_notification_id';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deletion = AccountDeletion::findOrFail($this->deletionId);

        return $this->brandedMail(
            subject: 'Your '.config('app.name').' account has been deactivated',
            greeting: 'Hello '.$deletion->name.',',
            intro: [
                'An administrator has deleted your E-Notary account. It is now deactivated, and you can no longer sign in.',
                ...$this->details($deletion),
                'Deleted on: '.$deletion->deleted_at->ist('d M Y, h:i A').' IST',
                'Reason: '.$deletion->reason,
                'Your applications and documents have been kept.',
            ],
            outro: [$this->supportLine()],
            reference: $deletion->reference,
        );
    }
}
