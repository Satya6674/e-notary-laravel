<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Module 12: support tickets end to end, with ownership, triage and audit. */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'public', array $extra = []): User
    {
        $this->n++;
        $user = User::create([
            'name' => "Demo Person {$this->n}", 'email' => "support{$this->n}@example.test", 'mobile' => '93300'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null] + $extra)->save();

        return $user;
    }

    private function open(User $user, array $data = []): SupportTicket
    {
        $this->actingAs($user)->post(route('support.store'), $data + [
            'subject' => 'Cannot upload my file', 'category' => 'documents', 'body' => 'The upload button does nothing.',
        ])->assertRedirect();

        return SupportTicket::latest('id')->firstOrFail();
    }

    public function test_the_full_ticket_lifecycle(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $citizen = $this->user();

        $ticket = $this->open($citizen, ['priority' => 'high']);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(1, $ticket->messages()->count());
        Notification::assertSentTo($admin, PortalNotification::class, fn ($n) => $n->title === 'New support ticket');

        $this->actingAs($admin)->post(route('support.reply', $ticket), ['body' => 'Please try a PDF under 5 MB.'])->assertSessionHas('status', 'Reply sent.');
        $this->assertSame('answered', $ticket->fresh()->status);
        Notification::assertSentTo($citizen, PortalNotification::class, fn ($n) => $n->title === 'Support ticket answered');

        $this->actingAs($citizen)->get(route('support.show', $ticket))->assertOk()
            ->assertSee('Please try a PDF under 5 MB.')->assertSee('Support team');

        $this->actingAs($citizen)->post(route('support.reply', $ticket), ['body' => 'That worked, thank you.']);
        $this->assertSame('open', $ticket->fresh()->status);
        Notification::assertSentTo($admin, PortalNotification::class, fn ($n) => $n->title === 'Support ticket updated');

        $this->actingAs($admin)->post(route('support.close', $ticket))->assertRedirect(route('support.show', $ticket));
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->closed_at);
        Notification::assertSentTo($citizen, PortalNotification::class, fn ($n) => $n->title === 'Support ticket closed');

        $this->actingAs($citizen)->post(route('support.reply', $ticket), ['body' => 'One more thing.'])->assertSessionHas('error', 'This ticket is closed.');
        $this->assertSame(3, $ticket->messages()->count());

        $this->assertSame(['ticket.opened', 'ticket.replied', 'ticket.replied', 'ticket.closed'],
            AuditLog::where('model_type', 'SupportTicket')->orderBy('id')->pluck('action')->all());
    }

    public function test_people_see_only_their_own_tickets(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $notary = $this->user('notary');
        $ticket = $this->open($alice, ['subject' => 'Alice private question']);

        $this->actingAs($bob)->get(route('support.index'))->assertOk()->assertDontSee('Alice private question');
        $this->actingAs($bob)->get(route('support.show', $ticket))->assertForbidden();
        $this->actingAs($bob)->post(route('support.reply', $ticket), ['body' => 'Hijack attempt'])->assertForbidden();
        $this->actingAs($bob)->post(route('support.close', $ticket))->assertForbidden();
        $this->actingAs($notary)->get(route('support.show', $ticket))->assertForbidden();
        $this->actingAs($bob)->patch(route('support.triage', $ticket), ['priority' => 'urgent'])->assertForbidden();
        $this->actingAs($alice)->patch(route('support.triage', $ticket), ['priority' => 'urgent'])->assertForbidden();

        $this->assertSame('normal', $ticket->fresh()->priority);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_a_ticket_cannot_point_at_someone_elses_application(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $application = \App\Models\NotaryRequest::create([
            'request_number' => 'REQ-SUP-1', 'user_id' => $bob->id, 'document_type' => 'affidavit', 'purpose' => 'x', 'status' => 'pending',
        ]);

        $this->actingAs($alice)->post(route('support.store'), [
            'subject' => 'About this application', 'category' => 'application', 'body' => 'Please look at it.', 'request_id' => $application->id,
        ])->assertSessionHasErrors('request_id');
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_admin_filters_and_search(): void
    {
        $admin = $this->user('admin');
        $alice = $this->user('public', ['name' => 'Alice Searchable']);
        $bob = $this->user();
        $this->open($alice, ['subject' => 'Payment question', 'category' => 'other', 'priority' => 'urgent']);
        $this->open($bob, ['subject' => 'Appointment time', 'category' => 'appointment', 'priority' => 'low']);

        $this->actingAs($admin)->get(route('support.index'))->assertOk()->assertSee('Payment question')->assertSee('Appointment time');
        $this->actingAs($admin)->get(route('support.index', ['q' => 'Searchable']))->assertOk()->assertSee('Payment question')->assertDontSee('Appointment time');
        $this->actingAs($admin)->get(route('support.index', ['category' => 'appointment']))->assertOk()->assertDontSee('Payment question')->assertSee('Appointment time');
        $this->actingAs($admin)->get(route('support.index', ['priority' => 'urgent']))->assertOk()->assertSee('Payment question')->assertDontSee('Appointment time');
        $this->actingAs($admin)->get(route('support.index', ['assigned' => 'none']))->assertOk()->assertSee('Payment question');
        $this->actingAs($admin)->get(route('support.index', ['assigned' => 'me']))->assertOk()->assertDontSee('Payment question');
        $this->actingAs($admin)->get(route('support.index', ['q' => '%']))->assertOk()->assertDontSee('Payment question');
        $this->actingAs($admin)->get(route('support.index', ['status' => 'bogus']))->assertSessionHasErrors('status');

        // A citizen's search never reaches other people's names.
        $this->actingAs($bob)->get(route('support.index', ['q' => 'Searchable']))->assertOk()->assertDontSee('Payment question');
    }

    public function test_triage_sets_priority_and_assignment_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $colleague = $this->user('admin');
        $retired = $this->user('admin', ['deactivated_at' => now()]);
        $citizen = $this->user();
        $ticket = $this->open($citizen);

        $this->actingAs($admin)->patch(route('support.triage', $ticket), ['priority' => 'urgent', 'assigned_to' => $retired->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($admin)->patch(route('support.triage', $ticket), ['priority' => 'urgent', 'assigned_to' => $citizen->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($admin)->patch(route('support.triage', $ticket), ['priority' => 'extreme'])->assertSessionHasErrors('priority');

        $this->actingAs($admin)->patch(route('support.triage', $ticket), ['priority' => 'urgent', 'assigned_to' => $colleague->id])->assertSessionHas('status', 'Ticket updated.');
        $this->assertSame(['urgent', $colleague->id], [$ticket->fresh()->priority, (int) $ticket->fresh()->assigned_to]);
        Notification::assertSentTo($colleague, PortalNotification::class, fn ($n) => $n->title === 'Support ticket assigned to you');

        $entry = AuditLog::where('action', 'ticket.triaged')->sole();
        $this->assertSame(['priority' => 'normal', 'assigned_to' => null], $entry->meta['from']);
        $this->assertSame(['priority' => 'urgent', 'assigned_to' => $colleague->id], $entry->meta['to']);

        $this->actingAs($admin)->patch(route('support.triage', $ticket), ['priority' => 'urgent', 'assigned_to' => $colleague->id])->assertSessionHas('status', 'Nothing changed.');
        $this->assertSame(1, AuditLog::where('action', 'ticket.triaged')->count());

        // With someone assigned, a requester's reply goes to that person only.
        Notification::fake();
        $this->actingAs($citizen)->post(route('support.reply', $ticket), ['body' => 'Any news?']);
        Notification::assertSentTo($colleague, PortalNotification::class);
        Notification::assertNotSentTo($admin, PortalNotification::class);
    }

    public function test_administrators_cannot_open_tickets_and_messages_are_escaped(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();

        $this->actingAs($admin)->post(route('support.store'), ['subject' => 'x y z', 'category' => 'other', 'body' => 'Admin ticket'])->assertForbidden();

        $ticket = $this->open($citizen, ['subject' => '<script>alert(1)</script>', 'body' => '<img src=x onerror=alert(2)>']);
        $this->actingAs($admin)->get(route('support.show', $ticket))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    public function test_an_account_ticket_offers_the_lost_mailbox_step_only_to_admins(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();
        $ticket = $this->open($citizen, ['subject' => 'I lost my email', 'category' => 'account']);
        $link = route('admin.users.email-replacement', ['user' => $citizen, 'ticket' => $ticket->id]);

        if (! EmailReplacement::supported()) {
            $this->actingAs($admin)->get(route('support.show', $ticket))->assertOk()->assertDontSee('Start lost mailbox replacement');
            $this->markTestSkipped('email_replacements is missing on this database.');
        }

        $this->actingAs($admin)->get(route('support.show', $ticket))->assertOk()->assertSee($link, false);
        $this->actingAs($citizen)->get(route('support.show', $ticket))->assertOk()->assertDontSee('Start lost mailbox replacement');

        // The replacement form is prefilled with the ticket, and still demands its own identity check.
        $this->actingAs($admin)->get($link)->assertOk()->assertSee('value="'.$ticket->id.'"', false);
        $this->assertSame($citizen->email, $citizen->fresh()->email);
    }

    public function test_closing_twice_is_harmless(): void
    {
        $citizen = $this->user();
        $ticket = $this->open($citizen);

        $this->actingAs($citizen)->post(route('support.close', $ticket));
        $this->actingAs($citizen)->post(route('support.close', $ticket))->assertSessionHas('status', 'This ticket is already closed.');

        $this->assertSame(1, AuditLog::where('action', 'ticket.closed')->count());
    }
}
