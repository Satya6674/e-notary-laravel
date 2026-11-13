<?php

namespace App\Notifications;

use App\Models\AccountDeletion;
use App\Notifications\Concerns\AccountNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a person their deleted account was restored. */
class AccountRestoredNotification extends Notification implements ShouldQueue
{
    use AccountNotice;

    public function __construct(public int $deletionId, string $id)
    {
        $this->id = $id;
        $this->afterCommit = true;
    }

    private function column(): string
    {
        return 'restore_notification_id';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deletion = AccountDeletion::findOrFail($this->deletionId);

        return $this->brandedMail(
            subject: 'Your '.config('app.name').' account has been restored',
            greeting: 'Hello '.$deletion->name.',',
            intro: [
                'An administrator has restored your E-Notary account.',
                ...$this->details($deletion),
                'Restored on: '.$deletion->restored_at->ist('d M Y, h:i A').' IST',
                ...$this->nextSteps($deletion),
            ],
            outro: [$this->supportLine()],
            reference: $deletion->reference,
        );
    }

    /** Every restore, by an administrator or through recovery, returns the account locked the same way. */
    private function nextSteps(AccountDeletion $deletion): array
    {
        $phoneProven = $deletion->user->hasVerifiedMobile();

        return array_filter([
            'Your previous password no longer works. Set a new one using the separate password reset email. If that link has expired, use Forgot Password with this email address.',
            $deletion->role === 'notary' ? 'Your Notary access stays closed until an administrator approves it again.' : null,
            $deletion->role === 'public' && ! $phoneProven ? 'You will be asked to confirm your mobile number again, as a number can change hands while an account is closed.' : null,
            $deletion->role === 'notary' && ! $phoneProven ? 'To reset your password by SMS in future, verify your mobile number again after you sign in.' : null,
        ]);
    }
}
