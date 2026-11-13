<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\NotaryRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Services\SupportAttachments;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Support tickets: citizens and notaries see their own, admins see all.
 *
 * Priority and assignment need migration 2026_11_04_000026. Before it runs they are hidden
 * and refused, and everything else works as before. Support never changes an account: a
 * lost mailbox goes to the separate email-replacement step, which has its own identity check.
 */
class SupportController extends Controller
{
    public function __construct(private SupportAttachments $files)
    {
    }

    /** The requester and administrators only. Always a download, checked against its upload hash. */
    public function attachment(Request $request, SupportTicket $ticket, int $attachment)
    {
        $this->authorizeTicket($request->user(), $ticket);
        // Looked up here, not by route binding, so a link before the migration is a 404, not an error.
        abort_unless(SupportTicketAttachment::supported(), 404);
        $attachment = SupportTicketAttachment::where('ticket_id', $ticket->id)->findOrFail($attachment);

        $response = $attachment->downloadResponse();
        abort_if($response === null, 404);

        AuditLog::record('ticket.attachment_downloaded', $ticket, ['attachment' => $attachment->id]);

        return $response;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $admin = $user->isAdmin();
        $triage = SupportTicket::triageSupported();

        $filters = $request->validate([
            'status'   => ['nullable', Rule::in(['all', ...SupportTicket::STATUSES])],
            'category' => ['nullable', Rule::in(['all', ...SupportTicket::CATEGORIES])],
            'priority' => ['nullable', Rule::in(['all', ...SupportTicket::PRIORITIES])],
            'assigned' => ['nullable', Rule::in(['all', 'me', 'none'])],
            'q'        => 'nullable|string|max:100',
        ]) + ['status' => 'all', 'category' => 'all', 'priority' => 'all', 'assigned' => 'all', 'q' => null];

        $query = SupportTicket::with(array_filter(['user', $triage ? 'assignee' : null]))->withCount('messages');

        if (! $admin) {
            $query->where('user_id', $user->id);
        }

        $query->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['category'] !== 'all', fn ($q) => $q->where('category', $filters['category']))
            ->when($triage && $filters['priority'] !== 'all', fn ($q) => $q->where('priority', $filters['priority']))
            ->when($admin && $triage && $filters['assigned'] === 'me', fn ($q) => $q->where('assigned_to', $user->id))
            ->when($admin && $triage && $filters['assigned'] === 'none', fn ($q) => $q->whereNull('assigned_to'));

        if (($search = trim((string) $filters['q'])) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('ticket_number', 'like', $like)->orWhere('subject', 'like', $like)
                ->when($admin, fn ($inner) => $inner->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))));
        }

        $counts = SupportTicket::query()->when(! $admin, fn ($q) => $q->where('user_id', $user->id))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('support.index', [
            'tickets'      => $query->latest('updated_at')->paginate(15)->withQueryString(),
            'filters'      => $filters,
            'status'       => $filters['status'],
            'counts'       => $counts,
            'triage'       => $triage,
            'attachments'  => SupportTicketAttachment::supported(),
            'applications' => NotaryRequest::where('user_id', $user->id)->latest()->get(['id', 'request_number']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        abort_if($user->isAdmin(), 403);

        $triage = SupportTicket::triageSupported();

        $data = $request->validate([
            'subject'    => 'required|string|min:3|max:200',
            'category'   => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority'   => $triage ? ['nullable', Rule::in(SupportTicket::PRIORITIES)] : ['nullable'],
            'body'       => 'required|string|min:5|max:2000',
            'request_id' => ['nullable', 'integer', Rule::exists('notary_requests', 'id')->where('user_id', $user->id)],
        ] + $this->files->rules(), $this->files->messages());

        $files = $request->file('attachments', []);

        [$ticket, $attached] = $this->files->guard($files, fn (array $stored) => DB::transaction(function () use ($data, $user, $triage, $stored) {
            $ticket = SupportTicket::create(array_filter([
                'ticket_number' => SupportTicket::generateNumber(),
                'user_id'       => $user->id,
                'request_id'    => $data['request_id'] ?? null,
                'subject'       => $data['subject'],
                'category'      => $data['category'],
                'priority'      => $triage ? ($data['priority'] ?? 'normal') : null,
            ], fn ($value) => $value !== null));

            $message = $ticket->messages()->create(['user_id' => $user->id, 'body' => $data['body']]);

            return [$ticket, $this->files->record($ticket, $message, $user, $stored)];
        }));

        AuditLog::record('ticket.opened', $ticket, array_filter(['category' => $ticket->category, 'priority' => $ticket->priority ?? null, 'attachments' => $attached]));

        // Administrators see new tickets in the app straight away.
        Notification::send($this->admins(), new PortalNotification(
            'New support ticket', $ticket->ticket_number, route('support.show', $ticket)
        ));

        return redirect()->route('support.show', $ticket)->with('status', 'Ticket created.');
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        $triage = SupportTicket::triageSupported();

        return view('support.show', [
            'ticket'      => $ticket->load(array_filter(['messages.user', 'user', 'request', $triage ? 'assignee' : null, SupportTicketAttachment::supported() ? 'messages.attachments' : null])),
            'attachments' => SupportTicketAttachment::supported(),
            'triage'      => $triage,
            'admins'      => $user->isAdmin() && $triage ? $this->admins() : collect(),
            // Support only points to the replacement step. That step checks identity itself.
            'replacement' => $user->isAdmin() && EmailReplacement::supported() && $ticket->user && ! $ticket->user->isAdmin()
                ? route('admin.users.email-replacement', ['user' => $ticket->user, 'ticket' => $ticket->id]) : null,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);

        if ($ticket->status === 'closed') {
            return back()->with('error', 'This ticket is closed.');
        }

        $data = $request->validate(['body' => 'required|string|min:2|max:2000'] + $this->files->rules(), $this->files->messages());

        $attached = $this->files->guard($request->file('attachments', []), fn (array $stored) => DB::transaction(function () use ($ticket, $user, $data, $stored) {
            $message = $ticket->messages()->create(['user_id' => $user->id, 'body' => $data['body']]);
            $ticket->update(['status' => $user->isAdmin() ? 'answered' : 'open']);

            return $this->files->record($ticket, $message, $user, $stored);
        }));

        AuditLog::record('ticket.replied', $ticket, array_filter(['by' => $user->isAdmin() ? 'admin' : 'requester', 'attachments' => $attached]));

        if ($user->isAdmin()) {
            $ticket->user?->notify(new PortalNotification(
                'Support ticket answered', $ticket->ticket_number, route('support.show', $ticket)
            ));
        } else {
            // The assigned administrator, or every administrator while nobody is assigned.
            $assignee = SupportTicket::triageSupported() && $ticket->assigned_to ? $this->admins()->firstWhere('id', $ticket->assigned_to) : null;
            Notification::send($assignee ? collect([$assignee]) : $this->admins(), new PortalNotification(
                'Support ticket updated', $ticket->ticket_number, route('support.show', $ticket)
            ));
        }

        return back()->with('status', 'Reply sent.');
    }

    /** Administrators only: set the priority and who is responsible. */
    public function triage(Request $request, SupportTicket $ticket)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(SupportTicket::triageSupported(), 404);

        $data = $request->validate([
            'priority'    => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'admin')->whereNull('deactivated_at')
                ->when(User::softDeleteSupported(), fn ($rule) => $rule->whereNull('deleted_at'))],
        ]);

        $before = ['priority' => $ticket->priority, 'assigned_to' => $ticket->assigned_to];
        $after = ['priority' => $data['priority'], 'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null];

        if ($before == $after) {
            return back()->with('status', 'Nothing changed.');
        }

        $ticket->forceFill($after)->save();

        AuditLog::record('ticket.triaged', $ticket, ['from' => $before, 'to' => $after]);

        if ($after['assigned_to'] && $after['assigned_to'] !== $before['assigned_to'] && $after['assigned_to'] !== $request->user()->id) {
            User::find($after['assigned_to'])?->notify(new PortalNotification('Support ticket assigned to you', $ticket->ticket_number, route('support.show', $ticket)));
        }

        return back()->with('status', 'Ticket updated.');
    }

    public function close(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);

        if ($ticket->status === 'closed') {
            return redirect()->route('support.show', $ticket)->with('status', 'This ticket is already closed.');
        }

        $ticket->forceFill(['status' => 'closed'] + (SupportTicket::triageSupported() ? ['closed_at' => now()] : []))->save();
        AuditLog::record('ticket.closed', $ticket, ['by' => $user->isAdmin() ? 'admin' : 'requester']);

        if ($user->isAdmin()) {
            $ticket->user?->notify(new PortalNotification('Support ticket closed', $ticket->ticket_number, route('support.show', $ticket)));
        }

        return redirect()->route('support.show', $ticket)->with('status', 'Ticket closed.');
    }

    private function authorizeTicket(User $user, SupportTicket $ticket): void
    {
        abort_unless($user->isAdmin() || $user->id === (int) $ticket->user_id, 403);
    }

    private function admins()
    {
        return User::where('role', 'admin')->notDeleted()->whereNull('deactivated_at')->orderBy('name')->get();
    }
}
