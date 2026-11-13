<?php

namespace App\Notifications;

use App\Models\NotaryRequest;
use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * One notification class for every notary-request lifecycle email.
 *
 * $event is one of: submitted, assigned, approved, rejected, completed.
 * The recipient is the request owner, except "assigned" which goes to the
 * assigned notary. Emails contain the reference number and status only;
 * never the uploaded files or identity details.
 */
class NotaryRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, BrandedMail;

    public const EVENTS = ['submitted', 'assigned', 'correction', 'approved', 'rejected', 'completed'];

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public NotaryRequest $request, public string $event)
    {
        $this->afterCommit = true;

        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException("Unknown notary request event [{$event}]");
        }
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

    /** The in-app copy shown under Notifications. */
    public function toArray(object $notifiable): array
    {
        $url = $this->event === 'assigned'
            ? route('notary.request.show', $this->request->id)
            : route('user.requests.show', $this->request->id);

        return [
            'title' => 'Application '.$this->event,
            'body'  => $this->request->request_number,
            'url'   => $url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref  = $this->request->request_number;
        $type = ucwords(str_replace('_', ' ', $this->request->document_type));
        $name = $notifiable->name;
        $app  = config('app.name');

        return match ($this->event) {
            'submitted' => $this->brandedMail(
                subject: "{$app}: request {$ref} received",
                greeting: "Hello {$name},",
                intro: [
                    "Your notarisation request for a {$type} has been received and is pending review.",
                    'You will get another email when a notary is assigned and when a decision is made.',
                ],
                actionText: 'View my requests',
                actionUrl: route('user.requests'),
                reference: $ref,
            ),
            'assigned' => $this->brandedMail(
                subject: "{$app}: request {$ref} assigned to you",
                greeting: "Hello {$name},",
                intro: [
                    "A {$type} request has been assigned to you for review.",
                    'Please log in to review the request and record your decision.',
                ],
                actionText: 'Open request',
                actionUrl: route('notary.request.show', $this->request->id),
                reference: $ref,
            ),
            'correction' => $this->brandedMail(
                subject: "{$app}: request {$ref} needs a correction",
                greeting: "Hello {$name},",
                intro: [
                    "Your {$type} request needs a correction before review can continue.",
                    'Reason given: '.$this->request->decision_remarks,
                ],
                actionText: 'Make the correction',
                actionUrl: route('user.requests.show', $this->request->id),
                reference: $ref,
            ),
            'approved' => $this->brandedMail(
                subject: "{$app}: request {$ref} approved",
                greeting: "Hello {$name},",
                intro: [
                    "Good news: your {$type} request has been approved by the reviewing notary.",
                    'You will receive a final email when the request is marked completed.',
                ],
                actionText: 'View request',
                actionUrl: route('user.requests.show', $this->request->id),
                reference: $ref,
            ),
            'rejected' => $this->brandedMail(
                subject: "{$app}: request {$ref} rejected",
                greeting: "Hello {$name},",
                intro: array_values(array_filter([
                    "Unfortunately your {$type} request could not be approved.",
                    $this->request->rejection_reason
                        ? 'Reason given: '.$this->request->rejection_reason
                        : null,
                    'You may submit a new request with corrected documents.',
                ])),
                actionText: 'View request',
                actionUrl: route('user.requests.show', $this->request->id),
                reference: $ref,
            ),
            'completed' => $this->brandedMail(
                subject: "{$app}: request {$ref} completed",
                greeting: "Hello {$name},",
                intro: [
                    "Your {$type} request has been marked completed.",
                ],
                actionText: 'View request',
                actionUrl: route('user.requests.show', $this->request->id),
                reference: $ref,
            ),
        };
    }
}
