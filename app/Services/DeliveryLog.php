<?php

namespace App\Services;

use App\Models\MessageDelivery;
use App\Support\Platform;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records outgoing messages for the Communication Center. Never stores full contact
 * details, message text or codes. A logging failure must not stop the message.
 */
class DeliveryLog
{
    public static function maskEmail(?string $email): string
    {
        if (! str_contains((string) $email, '@')) {
            return '***';
        }

        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 2).'***@'.$domain;
    }

    /**
     * True while a start script limits who may receive a real message. Unset means no
     * limit, except in Local Gmail mode, where it means nobody may receive mail.
     */
    public static function allowed(string $channel, ?string $recipient): bool
    {
        $address = strtolower(trim((string) $recipient));
        $localGmail = $channel === 'mail' && Platform::mailMode() === 'local_gmail';

        // Local Gmail sends only from its disposable database, so it can never reach the
        // accounts in a permanent one, whatever its recipient list says.
        if ($localGmail && ! Platform::onLocalGmailDatabase()) {
            return false;
        }

        $list = self::addresses("services.outbound.{$channel}");

        if ($channel === 'mail' && Platform::liveMailApplies()) {
            // LIVE_MAIL_RECIPIENTS=any: every legitimate recipient. Who that is, is decided by each
            // event (the account it concerns), never by this check.
            if (Platform::liveRecipientsOpen()) {
                return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
            }

            // Otherwise an explicit allowlist: an empty one means nobody, never everybody.
            if ($list === []) {
                return false;
            }
        }

        if ($list === []) {
            return ! $localGmail;
        }

        if (! in_array($address, $list, true)) {
            return false;
        }

        // An approved address that belongs to an Admin account also needs the owner's
        // confirmation for this run.
        return ! $localGmail || in_array($address, self::addresses('services.outbound.mail_confirmed'), true)
            || ! User::whereRaw('lower(email) = ?', [$address])->where('role', 'admin')->exists();
    }

    /** @return list<string> A comma-separated setting, trimmed and lower-cased. */
    public static function addresses(string $key): array
    {
        return array_values(array_filter(array_map(fn ($a) => strtolower(trim($a)), explode(',', (string) config($key)))));
    }

    public function sms(string $purpose, string $maskedMobile, string $status, ?string $reference = null, ?string $error = null, ?int $userId = null): void
    {
        $this->write(['channel' => 'sms', 'purpose' => $purpose, 'recipient' => $maskedMobile, 'status' => $status, 'provider_reference' => $reference, 'error' => $error, 'user_id' => $userId]);
    }

    public function queued(JobQueued $event): void
    {
        $job = $event->job;

        if ($job instanceof SendQueuedNotifications && $job->channels === ['mail']) {
            foreach ($job->notifiables as $notifiable) {
                $this->mail($job->notification, $notifiable, MessageDelivery::QUEUED);
            }
        }
    }

    /** Returning false cancels the send: a redelivered job must not repeat an accepted email. */
    public function sending(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail') {
            return null;
        }

        if ($this->alreadyAccepted($event->notification)) {
            return false;
        }

        $this->mail($event->notification, $event->notifiable, MessageDelivery::SENDING);

        return null;
    }

    /**
     * Why the MessageSending listener stopped a message, by notification id, until sent() records it.
     * Static because the listener and this recorder are separate calls within one send.
     *
     * @var array<string, string>
     */
    private static array $refusals = [];

    /** Remembers why a message was stopped, so its delivery record says so. */
    public static function refuse(?string $notificationId, string $reason): void
    {
        if ($notificationId) {
            self::$refusals[$notificationId] = $reason;
        }
    }

    /**
     * The mailer returns nothing when the allowlist or the Live Mail rules stopped the message. A log
     * mailer sends nothing. Stopped by a missing setting counts as failed, as the message should have
     * gone; stopped by a rule counts as blocked. Only a message the transport accepted is "accepted".
     */
    public function sent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $reason = $event->response ? null : (self::$refusals[$event->notification->id ?? ''] ?? null);
        unset(self::$refusals[$event->notification->id ?? '']);

        $this->mail($event->notification, $event->notifiable, match (true) {
            ! $event->response && str_starts_with((string) $reason, 'misconfigured:') => MessageDelivery::FAILED,
            ! $event->response => MessageDelivery::BLOCKED,
            Platform::mailMode() === 'safe_preview' => MessageDelivery::SIMULATED,
            default => MessageDelivery::ACCEPTED,
        }, $event->response?->getMessageId(), $reason);
    }

    /**
     * Sends a mail notification to one account at once, not through the queue, and returns its real
     * outcome from the delivery record: accepted, simulated, blocked or failed. A transport error is
     * recorded as failed with its class name only (a server reply can echo account details), and is
     * not thrown. Null when nothing was recorded, such as before the migrations or under a test fake.
     */
    public function sendNow(User $user, \Illuminate\Notifications\Notification $notification): ?string
    {
        $notification->id ??= (string) Str::uuid();

        // Refused by the Live Mail rules: recorded without building the mailer, as a missing setting
        // (such as no SMTP host) can make the transport itself fail to construct.
        if ($reason = Platform::liveMailRefusal($notification::class)) {
            \Illuminate\Support\Facades\Log::warning('Mail stopped by the Live Mail rules', ['reason' => $reason]);
            $this->mail($notification, $user, str_starts_with($reason, 'misconfigured:') ? MessageDelivery::FAILED : MessageDelivery::BLOCKED, null, $reason);

            return $this->ready() ? MessageDelivery::where('notification_id', $notification->id)->value('status') : null;
        }

        try {
            $user->notifyNow($notification);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Email could not be sent', ['to' => self::maskEmail($user->email), 'error' => class_basename($e)]);
            unset(self::$refusals[$notification->id]);
            $this->mail($notification, $user, MessageDelivery::FAILED, null, class_basename($e));

            return $this->ready() ? MessageDelivery::FAILED : null;
        }

        return $this->ready() ? MessageDelivery::where('notification_id', $notification->id)->value('status') : null;
    }

    public function failed(JobFailed $event): void
    {
        if ($id = $this->notificationId($event)) {
            MessageDelivery::where('notification_id', $id)->update([
                'status' => MessageDelivery::FAILED,
                'error'  => class_basename($event->exception),
            ]);
        }
    }

    /** A failed attempt that will be retried goes back to waiting, with the reason on show. */
    public function retrying(JobExceptionOccurred $event): void
    {
        if ($id = $this->notificationId($event)) {
            MessageDelivery::where('notification_id', $id)->where('status', MessageDelivery::SENDING)->update([
                'status' => MessageDelivery::QUEUED,
                'error'  => class_basename($event->exception),
            ]);
        }
    }

    private function notificationId(JobFailed|JobExceptionOccurred $event): ?string
    {
        try {
            $data = $event->job->payload()['data'] ?? [];
            $command = $data['command'] ?? null;

            if (! is_string($command) || (($data['commandName'] ?? null) !== SendQueuedNotifications::class && ! str_contains($command, 'SendQueuedNotifications'))) {
                return null;
            }

            // As Laravel's CallQueuedHandler reads it: plain, or encrypted for a ShouldBeEncrypted
            // notification (a password reset link). Matching the class name in the text missed those.
            $job = unserialize(str_starts_with($command, 'O:') ? $command : app(Encrypter::class)->decrypt($command));

            return $job instanceof SendQueuedNotifications ? $job->notification->id : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function alreadyAccepted(object $notification): bool
    {
        return $this->ready() && ($notification->id ?? null)
            && MessageDelivery::where('notification_id', $notification->id)->where('status', MessageDelivery::ACCEPTED)->exists();
    }

    /** The person used the link or the code, so that message did arrive. */
    public function confirm(string $channel, string $purpose, int $userId): void
    {
        if (! $this->ready()) {
            return;
        }

        MessageDelivery::where(['channel' => $channel, 'purpose' => $purpose, 'user_id' => $userId])
            ->where('status', MessageDelivery::ACCEPTED)->whereNull('confirmed_at')->latest('id')->limit(1)
            ->update(['confirmed_at' => now()]);
    }

    private function mail(object $notification, object $notifiable, string $status, ?string $reference = null, ?string $error = null): void
    {
        if (! $this->ready() || ! ($notification->id ?? null)) {
            return;
        }

        try {
            MessageDelivery::updateOrCreate(['notification_id' => $notification->id], array_filter([
                'channel'            => 'mail',
                'purpose'            => Str::snake(str_replace('Notification', '', class_basename($notification))),
                'recipient'          => self::maskEmail($notifiable->routeNotificationFor('mail', $notification) ?? $notifiable->email ?? null),
                'status'             => $status,
                'provider_reference' => $reference,
                'error'              => $error === null ? null : mb_substr($error, 0, 255),
                'user_id'            => $notifiable instanceof User ? $notifiable->id : null,
            ]) + ($status === MessageDelivery::ACCEPTED ? ['error' => null] : []));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function write(array $values): void
    {
        if (! $this->ready()) {
            return;
        }

        try {
            MessageDelivery::create($values);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function ready(): bool
    {
        return Platform::schemaReady();
    }
}
