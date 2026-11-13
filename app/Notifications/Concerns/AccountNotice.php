<?php

namespace App\Notifications\Concerns;

use App\Models\AccountDeletion;
use Illuminate\Bus\Queueable;

/** Shared by the deletion and restoration emails. */
trait AccountNotice
{
    use BrandedMail, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** A notice replaced by a retry stays silent, so one event never sends two emails. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return AccountDeletion::whereKey($this->deletionId)->value($this->column()) === $this->id;
    }

    /** Used only for a deleted account, whose own row holds a placeholder. Never from a request. */
    public function recipientAddress(): ?string
    {
        return AccountDeletion::whereKey($this->deletionId)->value('email');
    }

    private function details(AccountDeletion $deletion): array
    {
        return [
            'Name: '.$deletion->name,
            'Registered email: '.$deletion->email,
            'Account role: '.(['public' => 'Citizen', 'notary' => 'Notary', 'admin' => 'Admin'][$deletion->role] ?? $deletion->role),
        ];
    }

    private function supportLine(): string
    {
        $address = config('mail.support_address') ?: config('mail.from.address');

        return 'For help, contact E-Notary support at '.$address.' and quote the reference below.';
    }
}
