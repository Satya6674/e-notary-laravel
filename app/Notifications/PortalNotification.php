<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short in-app notice, with a queued email copy, for appointments and support replies. */
class PortalNotification extends Notification implements ShouldQueue
{
    use Queueable, BrandedMail;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    /**
     * The link's path inside this site, for the email button. The URL passed in is generated during
     * the web request, so its host comes from that request's Host header; the email button is rebuilt
     * on APP_URL instead (App\Support\AppUrl). The in-app copy keeps the URL as given.
     */
    public ?string $mailPath = null;

    public function __construct(public string $title, public string $body, public string $url)
    {
        $this->afterCommit = true;

        $base = rtrim(url('/'), '/');

        if ($base !== '' && str_starts_with($url, $base.'/')) {
            $this->mailPath = substr($url, strlen($base));
        } elseif (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $this->mailPath = $url;
        }
    }

    /** The email button: APP_URL plus the path, or the URL as given when it is not one of ours. */
    public function mailUrl(): string
    {
        return $this->mailPath !== null ? \App\Support\AppUrl::root().$this->mailPath : $this->url;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** The in-app copy is written at once; only the email waits for the queue worker. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail(
            subject: config('app.name').': '.$this->title,
            greeting: "Hello {$notifiable->name},",
            intro: [$this->title.': '.$this->body],
            actionText: 'Open',
            actionUrl: $this->mailUrl(),
        );
    }
}
