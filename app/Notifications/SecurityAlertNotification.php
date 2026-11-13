<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a person that something sensitive changed on their account. */
class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, BrandedMail;

    public const EVENTS = [
        'password_changed' => 'The password on your account was changed.',
        'mobile_verified'  => 'A mobile number was verified on your account.',
        'mobile_change_requested' => 'Someone who knows your password asked to change the mobile number on your account. The number changes only after a code sent to the new number is entered.',
        'mobile_changed'   => 'The mobile number on your account was changed.',
        'email_changed'    => 'The email address on your account was changed. Alerts now go to the new address.',
        // Changes an administrator made to the account. The new values are never included.
        'admin_password_set' => 'An administrator set a new password on your account. Every other signed-in session was ended.',
        'admin_email_changed' => 'An administrator changed the email address on your account. Alerts now go to the new address.',
        'admin_role_changed' => 'An administrator changed the role of your account.',
        'account_deactivated' => 'Your account was deactivated by an administrator. You cannot sign in until it is reactivated.',
        'account_reactivated' => 'Your account was reactivated by an administrator. You can sign in again.',
        // Two-factor sign-in on an Admin account.
        'mfa_enabled' => 'Two-factor sign-in was turned on for your account.',
        'mfa_disabled' => 'Two-factor sign-in was turned off for your account.',
        'mfa_recovery_used' => 'A two-factor recovery code was used to sign in to your account.',
        'mfa_recovery_regenerated' => 'New two-factor recovery codes were created for your account. The old ones no longer work.',
    ];

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $event)
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
            subject: 'Security alert for your '.config('app.name').' account',
            greeting: isset($notifiable->name) ? 'Hello '.$notifiable->name.',' : 'Hello,',
            intro: [self::EVENTS[$this->event]],
            outro: ['If this was you, no action is needed. If it was not, reset your password straight away and contact support.'],
        );
    }
}
