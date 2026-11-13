<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\NotaryRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Module 2: the Notary dashboard and the assignment that feeds it. A deactivated Notary is
 * never given work, open appointments follow their application, assignment is decided on
 * locked rows, and deactivation reports the open work without moving any of it.
 * Every record here is synthetic.
 */
class NotaryDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $citizen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin');
        $this->citizen = $this->user();
    }

    private function user(string $role = 'public', array $extra = []): User
    {
        static $i = 200;
        $i++;

        $user = User::create([
            'name' => "Demo Person {$i}", 'email' => "demo{$i}@example.test", 'mobile' => '9410000'.$i,
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        if ($extra) {
            $user->forceFill($extra)->save();
        }

        return $user;
    }

    private function application(array $extra = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->citizen->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic demo purpose', 'status' => 'pending',
        ], $extra));
    }

    private function appointment(NotaryRequest $application, string $status, ?int $notaryId): Appointment
    {
        return Appointment::create([
            'request_id' => $application->id, 'user_id' => $this->citizen->id, 'notary_id' => $notaryId,
            'status' => $status, 'scheduled_at' => now()->addDays(2), 'mode' => 'video',
        ]);
    }

    private function assign(NotaryRequest $application, User $notary, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.requests.assign', $application), ['notary_id' => $notary->id] + $extra);
    }

    // ---- 2.1: a deactivated Notary is never given work

    public function test_a_deactivated_notary_is_not_approved_and_is_not_offered(): void
    {
        $active = $this->user('notary');
        $deactivated = $this->user('notary', ['deactivated_at' => now()]);
        $application = $this->application();

        $this->assertTrue($active->isApprovedNotary());
        $this->assertFalse($deactivated->isApprovedNotary());

        $this->actingAs($this->admin)->get(route('admin.requests.show', $application))->assertOk()
            ->assertViewHas('notaries', fn ($list) => $list->contains('id', $active->id) && ! $list->contains('id', $deactivated->id));
    }

    public function test_a_deactivated_notary_cannot_be_assigned_even_by_a_crafted_request(): void
    {
        Notification::fake();
        $deactivated = $this->user('notary', ['deactivated_at' => now()]);
        $application = $this->application();

        $this->assign($application, $deactivated)->assertSessionHasErrors(['notary_id' => 'Choose an approved notary.']);

        $this->assertSame('pending', $application->fresh()->status);
        $this->assertNull($application->fresh()->notary_id);
        Notification::assertNothingSent();
    }

    public function test_work_left_with_a_deactivated_notary_is_flagged_and_never_moved(): void
    {
        $notary = $this->user('notary');
        $application = $this->application(['status' => 'assigned', 'notary_id' => $notary->id]);
        $booked = $this->appointment($application, 'confirmed', $notary->id);

        $this->actingAs($this->admin)->get(route('admin.requests.show', $application))
            ->assertDontSee('is deactivated and cannot work on it');

        $notary->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->admin)->get(route('admin.requests.show', $application))
            ->assertSee('is deactivated and cannot work on it');
        $this->assertSame($notary->id, (int) $application->fresh()->notary_id);
        $this->assertSame('assigned', $application->fresh()->status);
        $this->assertSame('confirmed', $booked->fresh()->status);
        $this->assertSame($notary->id, (int) $booked->fresh()->notary_id);
    }

    // ---- 2.2: open appointments follow the application, history stays

    public function test_an_appointment_booked_before_assignment_reaches_the_assigned_notary(): void
    {
        $notary = $this->user('notary');
        $application = $this->application();
        $booked = $this->appointment($application, 'requested', null);

        $this->assign($application, $notary)->assertSessionHas('status');

        $this->assertSame($notary->id, (int) $booked->fresh()->notary_id);
        $this->assertSame('requested', $booked->fresh()->status, 'assignment never changes an appointment status');
        $this->assertSame(1, AuditLog::where('action', 'appointment.notary_changed')->where('model_id', $booked->id)->count());

        $this->actingAs($notary)->get(route('notary.dashboard'))->assertOk()
            ->assertViewHas('upcoming', fn ($list) => $list->contains('id', $booked->id));
        $this->actingAs($notary)->patch(route('appointments.update', $booked), ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame('confirmed', $booked->fresh()->status);
    }

    public function test_reassignment_moves_only_open_appointments_and_keeps_history(): void
    {
        $first = $this->user('notary');
        $second = $this->user('notary');
        $application = $this->application(['status' => 'assigned', 'notary_id' => $first->id]);
        $requested = $this->appointment($application, 'requested', $first->id);
        $completed = $this->appointment($application, 'completed', $first->id);
        $cancelled = $this->appointment($application, 'cancelled', $first->id);

        $this->assign($application, $second, ['expected_notary_id' => $first->id])->assertSessionHas('status');

        $this->assertSame($second->id, (int) $requested->fresh()->notary_id);
        $this->assertSame('requested', $requested->fresh()->status);
        $this->assertSame($first->id, (int) $completed->fresh()->notary_id);
        $this->assertSame($first->id, (int) $cancelled->fresh()->notary_id);
        $this->assertSame(1, AuditLog::where('action', 'request.reassigned')->where('model_id', $application->id)->count());

        // The previous Notary no longer controls the open appointment; the new one does.
        $this->actingAs($first)->patch(route('appointments.update', $requested), ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($second)->patch(route('appointments.update', $requested), ['status' => 'confirmed'])->assertRedirect();
    }

    // ---- 2.3: decided on locked rows, never twice

    public function test_a_stale_page_cannot_assign_an_application_someone_else_assigned(): void
    {
        Notification::fake();
        $first = $this->user('notary');
        $second = $this->user('notary');
        $application = $this->application();

        // Both administrators loaded the page while it was unassigned.
        $this->assign($application, $first, ['expected_notary_id' => ''])->assertSessionHas('status');
        $this->assign($application, $second, ['expected_notary_id' => ''])
            ->assertSessionHas('error', 'This application was assigned by someone else while you were viewing it. Please check it again.');

        $this->assertSame($first->id, (int) $application->fresh()->notary_id);
        $this->assertSame(0, AuditLog::where('action', 'request.reassigned')->count());
        Notification::assertSentToTimes($first, NotaryRequestNotification::class, 1);
        Notification::assertNotSentTo($second, NotaryRequestNotification::class);
    }

    public function test_the_same_assignment_twice_changes_nothing_and_notifies_once(): void
    {
        Notification::fake();
        $notary = $this->user('notary');
        $application = $this->application();

        $this->assign($application, $notary)->assertSessionHas('status');
        $this->assign($application, $notary)->assertSessionHas('status', 'This request is already assigned to that notary.');

        Notification::assertSentToTimes($notary, NotaryRequestNotification::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'request.assigned')->where('model_id', $application->id)->count());
    }

    public function test_a_decided_application_cannot_be_assigned(): void
    {
        $notary = $this->user('notary');
        $other = $this->user('notary');
        $application = $this->application(['status' => 'approved', 'notary_id' => $notary->id]);

        $this->assign($application, $other)->assertSessionHas('error', 'Only pending or assigned requests can be assigned.');
        $this->assertSame($notary->id, (int) $application->fresh()->notary_id);
    }

    public function test_a_notary_cannot_be_assigned_their_own_application(): void
    {
        $notary = $this->user('notary');
        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $notary->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic', 'status' => 'pending',
        ]);

        $this->assign($application, $notary)->assertSessionHasErrors(['notary_id' => 'A notary cannot review their own application.']);
        $this->assertNull($application->fresh()->notary_id);
    }

    public function test_only_an_admin_can_assign(): void
    {
        $notary = $this->user('notary');
        $application = $this->application();

        foreach ([$this->citizen, $notary] as $actor) {
            $this->actingAs($actor)->post(route('admin.requests.assign', $application), ['notary_id' => $notary->id])->assertForbidden();
        }

        $this->assertNull($application->fresh()->notary_id);
    }

    // ---- 2.4: the exact open work is shown before and after deactivation, and nothing moves

    public function test_the_deactivate_confirmation_states_the_exact_open_work(): void
    {
        $notary = $this->user('notary', ['name' => 'Count Notary']);
        $this->application(['status' => 'assigned', 'notary_id' => $notary->id]);
        $approved = $this->application(['status' => 'approved', 'notary_id' => $notary->id]);
        $this->application(['status' => 'completed', 'notary_id' => $notary->id]);
        $this->appointment($approved, 'confirmed', $notary->id);
        $this->appointment($approved, 'cancelled', $notary->id);

        $html = $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'notary']))->assertOk()
            ->assertSee('This Notary has 2 open applications and 1 open appointments. They stay assigned until you reassign them.')
            ->getContent();

        // The confirmation is a modal with a required reason, and the form carries the counts it showed.
        $this->assertMatchesRegularExpression('/<form action="'.preg_quote(route('admin.users.destroy', $notary), '/').'" method="POST" data-confirm="[^"]+" data-reason>/', $html);
        $this->assertStringContainsString('name="open_applications" value="2"', $html);
        $this->assertStringContainsString('name="open_appointments" value="1"', $html);

        $this->assertSame(['applications' => 2, 'appointments' => 1], $notary->openNotaryWork());
    }

    public function test_deactivating_a_notary_reports_the_open_work_and_moves_none_of_it(): void
    {
        $notary = $this->user('notary');
        $assigned = $this->application(['status' => 'assigned', 'notary_id' => $notary->id]);
        $booked = $this->appointment($assigned, 'requested', $notary->id);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $notary), ['reason' => 'Synthetic test reason', 'open_applications' => 1, 'open_appointments' => 1])
            ->assertSessionHas('status', 'Account deactivated. 1 open applications and 1 open appointments are still assigned to this Notary. Reassign them from Applications.');

        $this->assertNotNull($notary->fresh()->deactivated_at);
        $this->assertSame($notary->id, (int) $assigned->fresh()->notary_id);
        $this->assertSame('assigned', $assigned->fresh()->status);
        $this->assertSame('requested', $booked->fresh()->status);
        $this->assertSame($notary->id, (int) $booked->fresh()->notary_id);

        $log = AuditLog::where('action', 'admin.user_deactivated')->sole();
        $this->assertSame(1, $log->meta['open_applications']);
        $this->assertSame(1, $log->meta['open_appointments']);
    }

    public function test_open_work_that_was_not_confirmed_blocks_deactivation(): void
    {
        $notary = $this->user('notary');
        $this->application(['status' => 'assigned', 'notary_id' => $notary->id]);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $notary), ['reason' => 'Synthetic test reason'])
            ->assertSessionHas('error', 'This Notary now has 1 open applications and 0 open appointments, which you have not confirmed. Nothing was changed. Review the work and confirm again.');

        $this->assertNull($notary->fresh()->deactivated_at);
        $this->assertSame(0, AuditLog::where('action', 'admin.user_deactivated')->count());
    }

    public function test_work_that_changed_after_the_page_loaded_blocks_deactivation(): void
    {
        $notary = $this->user('notary');

        // The page showed no work; an assignment arrived before the administrator confirmed.
        $this->application(['status' => 'assigned', 'notary_id' => $notary->id]);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $notary), ['reason' => 'Synthetic test reason', 'open_applications' => 0, 'open_appointments' => 0])
            ->assertSessionHas('error', 'This Notary now has 1 open applications and 0 open appointments, which you have not confirmed. Nothing was changed. Review the work and confirm again.');

        $this->assertNull($notary->fresh()->deactivated_at);
    }

    public function test_a_notary_with_no_open_work_deactivates_after_the_ordinary_confirmation(): void
    {
        $notary = $this->user('notary');

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $notary), ['reason' => 'Synthetic test reason', 'open_applications' => 0, 'open_appointments' => 0])
            ->assertSessionHas('status', 'Account deactivated.');

        $this->assertNotNull($notary->fresh()->deactivated_at);
        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->user('notary')), [])->assertSessionHasErrors('reason');
    }

    public function test_deactivating_a_citizen_says_nothing_about_notary_work(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'public']))->assertOk()
            ->assertDontSee('This Notary has');

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->citizen), ['reason' => 'Synthetic test reason'])
            ->assertSessionHas('status', 'Account deactivated.');
    }

    // ---- The dashboard itself shows only this Notary's work

    public function test_the_dashboard_counts_and_lists_only_this_notarys_work(): void
    {
        $mine = $this->user('notary');
        $other = $this->user('notary');
        $own = $this->application(['status' => 'assigned', 'notary_id' => $mine->id]);
        $theirs = $this->application(['status' => 'assigned', 'notary_id' => $other->id]);

        $this->actingAs($mine)->get(route('notary.dashboard'))->assertOk()
            ->assertViewHas('pendingCount', 1)
            ->assertSee($own->request_number)
            ->assertDontSee($theirs->request_number);

        $this->actingAs($mine)->get(route('notary.request.show', $theirs->id))->assertNotFound();
    }

    public function test_a_deactivated_notary_is_signed_out_of_the_dashboard(): void
    {
        $notary = $this->user('notary', ['deactivated_at' => now()]);

        $this->actingAs($notary)->get(route('notary.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
