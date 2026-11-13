<?php

namespace App\Http\Controllers\Contact;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactAcknowledgement;
use App\Notifications\ContactMessageReceived;
use App\Support\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stores messages from the public contact form (home page) and sends:
 *  - a short acknowledgement to the sender (no message echo, so the form
 *    cannot be abused to relay arbitrary text), and
 *  - a notification to the configured admin address (MAIL_ADMIN_ADDRESS),
 *    if one is configured. User input never chooses a recipient.
 *
 * Spam protection: hidden honeypot field + throttle middleware on the route (3 per 10 minutes per
 * IP), and the acknowledgement's own limits (see acknowledgementRefusal()). Both emails are queued
 * and recorded in the delivery history (contact_acknowledgement, contact_message_received).
 */
class ContactController extends Controller
{
    public const SUBJECTS = [
        'Document Verification Query',
        'Notary Registration',
        'Technical Support',
        'Payment Issue',
        'General Inquiry',
    ];

    public function store(Request $request)
    {
        // Honeypot: real browsers leave this hidden field empty. Bots that fill
        // it get a normal-looking redirect but nothing is stored or sent.
        if (filled($request->input('website'))) {
            return $this->done();
        }

        $data = $request->validate([
            'name'    => 'required|string|max:120',
            'email'   => 'required|email:rfc|max:255',
            'mobile'  => 'nullable|string|max:20',
            'subject' => 'required|string|in:'.implode(',', self::SUBJECTS),
            'message' => 'required|string|min:10|max:2000',
        ]);

        $message = DB::transaction(function () use ($data) {
            return ContactMessage::create([
                'name'    => $data['name'],
                'email'   => $data['email'],
                'subject' => $data['subject'].(filled($data['mobile'] ?? null) ? ' (mobile: '.$data['mobile'].')' : ''),
                'message' => $data['message'],
                'is_read' => false,
            ]);
        });

        if (($skip = $this->acknowledgementRefusal($message)) === null) {
            Notification::route('mail', $message->email)
                ->notify(new ContactAcknowledgement($message));
        } else {
            // The message itself is stored and reaches the Admin either way. No address is logged.
            Log::info('Contact acknowledgement not sent', ['message_id' => $message->id, 'reason' => $skip]);
        }

        // Without a configured address, the message goes to the administrator accounts.
        if ($admin = config('mail.admin_address')) {
            Notification::route('mail', $admin)
                ->notify(new ContactMessageReceived($message));
        } else {
            Notification::send(
                User::where('role', 'admin')->when(Platform::schemaReady(), fn ($q) => $q->whereNull('deactivated_at'))->get(),
                new ContactMessageReceived($message)
            );
        }

        return $this->done();
    }

    /** One acknowledgement per address in this many hours, however many messages it sends. */
    public const ACK_PER_ADDRESS_HOURS = 24;

    /**
     * Why the visitor's acknowledgement is not sent, or null when it may be. The address was typed by
     * the visitor and is unproven, so the form must not become a way to send mail to someone who never
     * asked, on top of the per-IP form throttle and the honeypot:
     *  - duplicate: the same address (any case) sent another message in the previous 24 h, so only a
     *    first message after a quiet day is acknowledged (durable check on the stored messages, plus
     *    an atomic cache claim for two submissions at the same moment);
     *  - own_address: the address is this application's own sender or Admin mailbox (no mail loops);
     *  - daily_cap: the site-wide daily number of acknowledgements (CONTACT_ACK_DAILY_LIMIT) is reached.
     */
    private function acknowledgementRefusal(ContactMessage $message): ?string
    {
        $address = strtolower(trim((string) $message->email));
        $own = array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), [config('mail.from.address'), config('mail.admin_address')]));

        if (in_array($address, $own, true)) {
            return 'own_address';
        }

        $earlier = ContactMessage::whereRaw('lower(email) = ?', [$address])
            ->where('id', '<', $message->id)
            ->where('created_at', '>=', now()->subHours(self::ACK_PER_ADDRESS_HOURS))
            ->exists();

        if ($earlier || ! Cache::add('contact-ack:'.hash('sha256', $address), true, now()->addHours(self::ACK_PER_ADDRESS_HOURS))) {
            return 'duplicate';
        }

        $cap = max(0, (int) config('mail.contact_ack_daily_limit', 100));

        if (RateLimiter::tooManyAttempts('contact-ack:site', $cap)) {
            return 'daily_cap';
        }

        RateLimiter::hit('contact-ack:site', 86400);

        return null;
    }

    private function done()
    {
        return redirect()->to(route('home').'#contact')
            ->with('contact_status', 'Thank you. Your message has been received. Our team will reply by email.');
    }
}
