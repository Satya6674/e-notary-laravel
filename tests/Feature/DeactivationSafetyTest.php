<?php

namespace Tests\Feature;

use App\Http\Controllers\AppointmentController;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The rules the deactivation race fix relies on, one request at a time. The real concurrency is
 * proven on MariaDB by DeactivationConcurrencyTest. Every account here is synthetic.
 */
class DeactivationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->make('admin');
    }

    private function make(string $role): User
    {
        $n = ++$this->n;

        $user = User::create([
            'name' => "Safe {$role} {$n}", 'email' => "safe-{$role}-{$n}@example.test", 'mobile' => '94555'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function application(User $citizen, ?User $notary): NotaryRequest
    {
        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $citizen->id,
            'document_type' => 'affidavit', 'purpose' => 'Synthetic', 'status' => 'pending',
        ]);

        if ($notary) {
            $application->forceFill(['status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id])->save();
        }

        return $application->fresh();
    }

    private function book(User $citizen, NotaryRequest $application)
    {
        return $this->actingAs($citizen)->post('/appointments', [
            'request_id' => $application->id, 'scheduled_at' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d H:i'), 'mode' => 'video',
        ]);
    }

    private function deactivate(User $target, array $confirm = [])
    {
        return $this->actingAs($this->admin)->delete(route('admin.users.destroy', $target), ['reason' => 'Synthetic deactivation.'] + $confirm);
    }

    public function test_a_booking_after_the_assigned_notary_was_deactivated_is_refused_and_nothing_moves(): void
    {
        $citizen = $this->make('public');
        $notary = $this->make('notary');
        $application = $this->application($citizen, $notary);

        $this->deactivate($notary, ['open_applications' => 1, 'open_appointments' => 0])->assertSessionHas('status');

        $this->book($citizen, $application)->assertSessionHas('error', AppointmentController::NOTARY_UNAVAILABLE);

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, AuditLog::where('action', 'appointment.requested')->count());
        $fresh = $application->fresh();
        $this->assertSame(NotaryRequest::STATUS_ASSIGNED, $fresh->status, 'Not closed.');
        $this->assertSame($notary->id, (int) $fresh->notary_id, 'Not silently reassigned.');
        Notification::assertNotSentTo($notary, \App\Notifications\PortalNotification::class);
        Notification::assertNotSentTo($notary, \App\Notifications\NotaryRequestNotification::class);
        Notification::assertSentTo($notary, \App\Notifications\SecurityAlertNotification::class, fn ($n) => $n->event === 'account_deactivated');
    }

    public function test_a_booking_with_a_notary_whose_approval_was_withdrawn_is_refused(): void
    {
        $citizen = $this->make('public');
        $notary = $this->make('notary');
        $application = $this->application($citizen, $notary);
        $notary->forceFill(['notary_approved_at' => null])->save();

        $this->book($citizen, $application)->assertSessionHas('error', AppointmentController::NOTARY_UNAVAILABLE);
        $this->assertSame(0, Appointment::count());
    }

    public function test_booking_still_works_with_an_active_notary_and_with_no_notary_yet(): void
    {
        $citizen = $this->make('public');
        $notary = $this->make('notary');

        $assigned = $this->application($citizen, $notary);
        $this->book($citizen, $assigned)->assertSessionHas('status', 'Appointment requested.');
        $this->assertSame($notary->id, (int) Appointment::where('request_id', $assigned->id)->sole()->notary_id);

        $pending = $this->application($citizen, null);
        $this->book($citizen, $pending)->assertSessionHas('status', 'Appointment requested.');
        $this->assertNull(Appointment::where('request_id', $pending->id)->sole()->notary_id);
    }

    public function test_after_reassignment_to_an_active_notary_the_booking_goes_through(): void
    {
        $citizen = $this->make('public');
        $first = $this->make('notary');
        $second = $this->make('notary');
        $application = $this->application($citizen, $first);

        $this->deactivate($first, ['open_applications' => 1, 'open_appointments' => 0]);
        $this->book($citizen, $application)->assertSessionHas('error', AppointmentController::NOTARY_UNAVAILABLE);

        $this->actingAs($this->admin)->post(route('admin.requests.assign', $application), ['notary_id' => $second->id, 'expected_notary_id' => $first->id])
            ->assertSessionHas('status');
        $this->book($citizen, $application->fresh())->assertSessionHas('status', 'Appointment requested.');

        $this->assertSame($second->id, (int) Appointment::sole()->notary_id);
    }

    public function test_a_second_booking_for_the_same_application_is_refused(): void
    {
        $citizen = $this->make('public');
        $application = $this->application($citizen, $this->make('notary'));

        $this->book($citizen, $application)->assertSessionHas('status');
        $this->book($citizen, $application)->assertSessionHas('error', 'This application already has an open appointment.');

        $this->assertSame(1, Appointment::count());
    }

    public function test_deactivation_leaves_confirmed_open_work_exactly_where_it_was(): void
    {
        $citizen = $this->make('public');
        $notary = $this->make('notary');
        $application = $this->application($citizen, $notary);
        $this->book($citizen, $application)->assertSessionHas('status');

        // An unconfirmed or stale count changes nothing.
        $this->deactivate($notary)->assertSessionHas('error');
        $this->deactivate($notary, ['open_applications' => 1, 'open_appointments' => 0])->assertSessionHas('error');
        $this->assertNull($notary->fresh()->deactivated_at);

        // Confirmed exactly: deactivated, and the work is neither moved, closed nor deleted.
        $this->deactivate($notary, ['open_applications' => 1, 'open_appointments' => 1])->assertSessionHas('status');
        $this->assertNotNull($notary->fresh()->deactivated_at);
        $this->assertSame(NotaryRequest::STATUS_ASSIGNED, $application->fresh()->status);
        $this->assertSame($notary->id, (int) $application->fresh()->notary_id);
        $appointment = Appointment::sole();
        $this->assertSame('requested', $appointment->status);
        $this->assertSame($notary->id, (int) $appointment->notary_id);
    }

    public function test_an_admin_can_deactivate_another_admin_but_not_themselves(): void
    {
        $other = $this->make('admin');

        $this->deactivate($this->admin)->assertSessionHas('error', 'You cannot deactivate your own account.');
        $this->deactivate($other)->assertSessionHas('status', 'Account deactivated.');

        $this->assertNotNull($other->fresh()->deactivated_at);
        $this->assertNull($this->admin->fresh()->deactivated_at);
    }
}
