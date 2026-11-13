<?php

namespace App\Notifications;

use App\Models\AccountRecoveryRequest;
use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The emails about a recovery request. The address always comes from the stored request, and
 * nothing about the old account beyond the reference is included.
 */
class AccountRecoveryNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $recoveryId, public string $notice, string $id)
    {
        $this->id = $id;
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** A notice replaced by a retry stays silent, so one event never sends two emails. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->recovery()?->{'' .$this->notice.'_notification_id'} === $this->id;
    }

    public function recipientAddress(): string
    {
        $recovery = $this->recovery();

        return $this->notice === 'alert' ? $recovery->deletion->email : $recovery->verified_email;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $recovery = $this->recovery();
        $name = config('app.name');
        $support = 'For help, contact E-Notary support at '.(config('mail.support_address') ?: config('mail.from.address')).' and quote the reference below.';

        [$subject, $lines] = match ($this->notice) {
            'requested' => ["We received your {$name} account recovery request", [
                'We received your request to recover a previous account.',
                'It is pending Admin approval. Nothing has been restored, and nothing has changed yet.',
            ]],
            'alert' => ["Security alert: a recovery request was made for your {$name} account", [
                'Someone who confirmed a mobile number linked to your deleted account has asked to recover it, using a different email address.',
                'The account has not been restored. An administrator must review the request first.',
                'If this was not you, contact support straight away.',
            ]],
            'approved' => ["Your {$name} account recovery was approved", [
                'An administrator approved your account recovery request.',
                'The new account you registered has been closed. Accounts are never merged, and it stays archived.',
                'Separate emails to the registered address of your original account confirm the restoration and let you set a new password.',
            ]],
            'rejected' => $recovery->status === AccountRecoveryRequest::EXPIRED ? ["Your {$name} account recovery request has expired", [
                'Your account recovery request was not decided within '.AccountRecoveryRequest::MAX_AGE_DAYS.' days, so it has been closed. Nothing was restored.',
                'If you still want your previous account back, sign in and ask again. You will be sent a fresh code to the address of that account.',
            ]] : ["Your {$name} account recovery request was closed", [
                'An administrator closed your account recovery request without restoring the account.',
                'Reason: '.$recovery->decision_reason,
                'Your previous account stays archived, and you can continue with your new account.',
            ]],
            default => ["Your {$name} account recovery request was closed", [
                'An administrator closed your account recovery request without restoring the account.',
                'Reason: '.$recovery->decision_reason,
                'Your previous account stays archived, and you can continue with your new account.',
            ]],
        };

        return $this->brandedMail(
            subject: $subject,
            greeting: 'Hello,',
            intro: $lines,
            outro: [$support],
            reference: $recovery->reference,
        );
    }

    private function recovery(): ?AccountRecoveryRequest
    {
        return AccountRecoveryRequest::find($this->recoveryId);
    }
}
