<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\DeliveryLog;
use App\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Queues one acceptance email to one account's own address. It goes through the same
 * queue and worker as every other notification, is tried once, and cannot be repeated
 * for that account within the hour.
 */
class LocalGmailTest extends Command
{
    protected $signature = 'enotary:local-gmail-test {user : Account id}';

    protected $description = 'Queue one controlled test email to an approved local account';

    public function handle(): int
    {
        $user = User::find($this->argument('user'));

        $problem = match (true) {
            Platform::mailMode() !== 'local_gmail' => 'This works only in Local Gmail mode.',
            ! Platform::onLocalGmailDatabase() => 'Local Gmail runs only on the '.Platform::LOCAL_GMAIL_DATABASE.' database. Nothing was queued.',
            ! $user => 'No such account.',
            ! DeliveryLog::allowed('mail', $user->email) => 'That account is not an approved recipient.',
            ! Cache::add("local-gmail-test:{$user->id}", true, 3600) => 'This account already had its test within the hour.',
            default => null,
        };

        if ($problem) {
            $this->error($problem);

            return self::FAILURE;
        }

        $notification = new PortalNotification('Email test', 'This is a controlled test of local email. No action is needed.', url('/'));
        $notification->tries = 1;
        $user->notify($notification);

        $this->info("Queued for {$user->role} #{$user->id}, ".DeliveryLog::maskEmail($user->email).'. Queued is not sent: see the Delivery page.');

        return self::SUCCESS;
    }
}
