<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PortalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Module 1: an Admin can reject a pending Notary application, with a reason the Notary sees. */
class NotaryRejectionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'public', array $extra = []): User
    {
        $this->n++;
        $user = User::create([
            'name' => "Demo Person {$this->n}", 'email' => "reject{$this->n}@example.test", 'mobile' => '93100'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $extra && $user->forceFill($extra)->save();

        return $user;
    }

    public function test_a_pending_application_is_rejected_with_a_reason_and_the_notary_is_told(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $notary = $this->user('notary');

        $this->actingAs($admin)->get(route('admin.notaries.index'))->assertOk()
            ->assertSee(route('admin.notaries.reject', $notary), false);

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Enrolment certificate is unreadable.'])
            ->assertRedirect(route('admin.notaries.index'))->assertSessionHas('status', 'Notary application rejected.');

        $entry = AuditLog::where('action', 'admin.notary_unapproved')->sole();
        $this->assertSame(['reason' => 'Enrolment certificate is unreadable.', 'from' => 'pending'], $entry->meta);
        $this->assertTrue($notary->fresh()->isRejectedNotary());

        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->title === 'Notary application rejected'
            && str_contains($n->body, 'Enrolment certificate is unreadable.') && $n->url === route('notary.pending'));
        // Other administrators get the account number only.
        Notification::assertNotSentTo($admin, PortalNotification::class);

        $this->actingAs($notary->fresh())->get(route('notary.pending'))->assertOk()
            ->assertSee('An administrator did not approve your notary account.')
            ->assertSee('Enrolment certificate is unreadable.');

        $this->actingAs($admin)->get(route('admin.notaries.index', ['status' => 'rejected']))->assertOk()->assertSee($notary->email);
        $this->actingAs($admin)->get(route('admin.notaries.index', ['status' => 'pending']))->assertOk()->assertDontSee($notary->email);
    }

    /**
     * Audit B-4: withdrawing an approval used to leave the Notary's assigned applications and
     * appointments with someone who can no longer open the portal, and nobody was told.
     */
    public function test_approval_is_not_withdrawn_while_the_notary_still_has_open_work(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $notary = $this->user('notary', ['notary_approved_at' => now(), 'mobile_verified_at' => now()]);
        $citizen = $this->user();
        $application = \App\Models\NotaryRequest::create([
            'request_number' => \App\Models\NotaryRequest::generateRequestNumber(), 'user_id' => $citizen->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'assigned', 'notary_id' => $notary->id,
        ]);
        \App\Models\Appointment::create([
            'request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id,
            'scheduled_at' => now()->addDays(2), 'mode' => 'video', 'status' => 'confirmed',
        ]);

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Licence lapsed, approval withdrawn.'])
            ->assertRedirect()
            ->assertSessionHas('error', 'This Notary still has 1 open applications and 1 open appointments. Nothing was changed. Reassign them first, or deactivate the account to stop access at once.');

        $this->assertNotNull($notary->fresh()->notary_approved_at);
        $this->assertSame(0, AuditLog::where('action', 'admin.notary_unapproved')->count());
        Notification::assertNothingSent();

        // Once the work is elsewhere, the approval can be withdrawn.
        $application->update(['status' => 'completed']);
        \App\Models\Appointment::query()->update(['status' => 'completed']);

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Licence lapsed, approval withdrawn.'])
            ->assertRedirect(route('admin.notaries.index'))->assertSessionHas('status', 'Notary approval withdrawn.');

        $this->assertNull($notary->fresh()->notary_approved_at);
        $this->assertSame('approved', AuditLog::where('action', 'admin.notary_unapproved')->sole()->meta['from']);
    }

    public function test_a_reason_is_required(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary', ['notary_approved_at' => now()]);

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary))->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'short'])->assertSessionHasErrors('reason');

        $this->assertNotNull($notary->fresh()->notary_approved_at);
        $this->assertSame(0, AuditLog::where('action', 'admin.notary_unapproved')->count());
    }

    public function test_rejecting_twice_records_one_decision(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Enrolment certificate is unreadable.']);
        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Enrolment certificate is unreadable.'])
            ->assertSessionHas('status', 'This notary application is already rejected.');

        $this->assertSame(1, AuditLog::where('action', 'admin.notary_unapproved')->count());
    }

    public function test_only_an_admin_can_reject_and_only_a_notary_account(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();
        $notary = $this->user('notary');
        $other = $this->user('notary', ['notary_approved_at' => now()]);

        $this->actingAs($other)->post(route('admin.notaries.reject', $notary), ['reason' => 'Trying to reject a colleague.'])->assertForbidden();
        $this->actingAs($citizen)->post(route('admin.notaries.reject', $notary), ['reason' => 'Trying to reject a notary.'])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.notaries.reject', $citizen), ['reason' => 'Not a notary account.'])
            ->assertSessionHas('error', 'Only notary accounts can be rejected.');

        $this->assertSame(0, AuditLog::where('action', 'admin.notary_unapproved')->count());
    }

    public function test_a_rejected_notary_still_has_no_portal_pages_and_is_pointed_to_the_contact_form(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary', ['mobile_verified_at' => now()]);
        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Enrolment certificate is unreadable.']);

        $this->actingAs($notary->fresh())->get(route('notary.pending'))->assertOk()->assertSee(route('home').'#contact', false);

        foreach ([route('notary.dashboard'), route('support.index'), route('appointments.index')] as $url) {
            $this->actingAs($notary->fresh())->get($url)->assertRedirect(route('notary.pending'));
        }
    }
}
