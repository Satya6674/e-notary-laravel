<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\ContactMessage;
use App\Models\PrivacyRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\TemporaryAccessGrant;
use App\Services\ConsentService;
use App\Services\VerificationLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Each person's own privacy requests and a copy of their own data. */
class PrivacyController extends Controller
{
    public function index(Request $request, ConsentService $consent)
    {
        $user = $request->user();

        return view('privacy-center.index', [
            'requests'       => PrivacyRequest::where('user_id', $user->id)->latest()->get(),
            'consents'       => $consent->history($user),
            'consentCurrent' => $consent->isCurrent($user),
            'versions'       => $consent->versions(),
        ]);
    }

    /** Accept the Terms and Privacy Policy in force now. Each acceptance is kept, never replaced. */
    public function consent(Request $request, ConsentService $consent)
    {
        $request->validate(['accept_terms' => 'accepted'], ['accept_terms.accepted' => 'Tick the box to accept the Terms of Use and the Privacy Policy.']);

        $user = $request->user();

        if ($consent->isCurrent($user)) {
            return back()->with('status', 'You have already accepted the current versions.');
        }

        $consent->record($user, 'privacy_center');

        return back()->with('status', 'Thank you. Your acceptance has been recorded.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in(PrivacyRequest::TYPES)],
            'details' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (PrivacyRequest::where(['user_id' => $user->id, 'type' => $data['type']])->whereIn('status', ['open', 'in_progress'])->exists()) {
            return back()->with('error', 'You already have an open request of this type.');
        }

        $privacy = PrivacyRequest::create($data + ['user_id' => $user->id]);

        AuditLog::record('privacy.requested', $privacy, ['type' => $data['type']]);

        return back()->with('status', 'Your request has been recorded.');
    }

    /** The person's own records as JSON. Uploaded files are not included, and neither are secrets. */
    public function export(Request $request)
    {
        $user = $request->user();

        AuditLog::record('privacy.exported', $user);

        $applications = NotaryRequest::withTrashed()->where('user_id', $user->id)->get();

        $body = json_encode([
            'generated_at' => now()->toIso8601String(),
            'profile'      => $user->only(['name', 'email', 'mobile', 'role', 'locale', 'email_verified_at', 'email_verification_method', 'mobile_verified_at', 'mobile_verification_method', 'mobile_verification_status', 'mobile_fallback_verified_at', 'mobile_manual_verified_at', 'created_at']),
            // Masked targets, no hash, no code, and no administrator's name or note.
            'verification_history' => app(VerificationLedger::class)->exportFor($user),
            'temporary_access' => TemporaryAccessGrant::supported()
                ? TemporaryAccessGrant::where('user_id', $user->id)->oldest('id')->get()->map(fn (TemporaryAccessGrant $grant) => [
                    'granted_at' => $grant->created_at?->toIso8601String(),
                    'expires_at' => $grant->expires_at?->toIso8601String(),
                    'revoked_at' => $grant->revoked_at?->toIso8601String(),
                    'status' => $grant->status(),
                ])->values()->all()
                : [],
            'applications' => $applications->map->only(['request_number', 'document_type', 'purpose', 'status', 'remarks', 'decision_remarks', 'service_details', 'created_at', 'decided_at']),
            'documents'    => Document::withTrashed()->with(['request' => fn ($query) => $query->withTrashed()])->whereIn('request_id', $applications->pluck('id'))->get()->map(fn (Document $document) => $document->only(['title', 'version', 'superseded_at', 'certified_at', 'mime_type', 'file_size', 'created_at', 'deleted_at']) + [
                // The same answer the public check gives.
                'status' => $document->trashed() ? 'removed' : ($document->lifecycleStatus(checkFile: true) ?? 'current'),
            ]),
            'appointments' => Appointment::where('user_id', $user->id)->get(['scheduled_at', 'mode', 'status', 'notes']),
            'privacy_requests' => PrivacyRequest::where('user_id', $user->id)->get(['type', 'details', 'status', 'resolution', 'created_at', 'resolved_at']),
            'consents'         => app(ConsentService::class)->history($user)->map(fn (AuditLog $entry) => [
                'terms_version'   => $entry->meta['terms_version'] ?? null,
                'privacy_version' => $entry->meta['privacy_version'] ?? null,
                'source'          => $entry->meta['source'] ?? null,
                'accepted_at'     => $entry->created_at?->toIso8601String(),
            ])->values(),
            // Support conversations. Staff are shown as "support", never by name.
            'support_tickets' => SupportTicket::where('user_id', $user->id)
                ->with(['messages' => fn ($query) => $query->oldest('id')] + (SupportTicketAttachment::supported() ? ['messages.attachments'] : []))
                ->oldest('id')->get()
                ->map(fn (SupportTicket $ticket) => $ticket->only(['ticket_number', 'subject', 'category', 'status', 'created_at']) + [
                    'messages' => $ticket->messages->map(fn ($message) => [
                        'from'        => (int) $message->user_id === $user->id ? 'you' : 'support',
                        'body'        => $message->body,
                        'sent_at'     => $message->created_at?->toIso8601String(),
                        'attachments' => SupportTicketAttachment::supported() ? $message->attachments->pluck('original_name')->values() : [],
                    ])->values(),
                ])->values(),
            'contact_messages' => ContactMessage::whereRaw('LOWER(email) = ?', [Str::lower($user->email)])->oldest('id')
                ->get(['subject', 'message', 'created_at']),
            'notifications'    => $user->notifications()->oldest()->get(['type', 'data', 'read_at', 'created_at'])
                ->map(fn ($n) => ['type' => class_basename($n->type), 'data' => $n->data, 'read_at' => $n->read_at, 'created_at' => $n->created_at]),
            'sign_in_activity' => AuditLog::where('user_id', $user->id)->where('action', 'like', 'auth.%')->latest('id')->limit(200)
                ->get(['action', 'ip_address', 'device', 'created_at']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($body, 200, [
            'Content-Type'           => 'application/json; charset=UTF-8',
            'Content-Disposition'    => 'attachment; filename="enotary-my-data.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }
}
