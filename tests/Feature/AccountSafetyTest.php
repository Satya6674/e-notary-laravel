<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SMS caps, safe deletion, account protection, reminders, IST, QR states and PDFs. */
class AccountSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function user(string $role = 'public', array $extra = []): User
    {
        static $i = 600;
        $i++;

        return User::create(array_merge([
            'name' => "Person {$i}", 'email' => "s{$i}@example.test", 'mobile' => '9300000'.$i,
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ], $extra));
    }

    private function application(User $owner, array $extra = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ], $extra));
    }

    private function otp(): OtpService
    {
        $this->mock(SmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));

        return app(OtpService::class);
    }

    // ---- H8: SMS caps

    public function test_a_number_is_capped_per_day_even_across_hours(): void
    {
        // Midday: this walks forward over two hours, and the daily count resets at midnight.
        $this->travelTo(now()->setTime(12, 0));

        $service = $this->otp();

        for ($i = 0; $i < OtpService::MAX_PER_DAY; $i++) {
            $this->assertSame(OtpService::ISSUED, $service->issue('9100000011'));
            $this->travel(13)->minutes();
        }

        $this->assertSame(OtpService::DAILY_LIMIT, $service->issue('9100000011'));
    }

    public function test_the_whole_site_stops_at_the_daily_budget(): void
    {
        config(['services.msg91.daily_limit' => 2]);
        $service = $this->otp();

        $this->assertSame(OtpService::ISSUED, $service->issue('9100000021'));
        $this->assertSame(OtpService::ISSUED, $service->issue('9100000022'));
        $this->assertSame(OtpService::DAILY_LIMIT, $service->issue('9100000023'));
        $this->assertSame(2, $service->sentToday());
    }

    public function test_one_ip_is_capped_per_day_across_many_numbers(): void
    {
        $service = $this->otp();

        Cache::put('otp-day:'.now()->format('Ymd').':ip:203.0.113.9', OtpService::MAX_PER_IP_DAY, now()->addDay());

        $this->assertSame(OtpService::DAILY_LIMIT, $service->issue('9100000031', '203.0.113.9'));
        $this->assertSame(OtpService::ISSUED, $service->issue('9100000032', '203.0.113.10'));
    }

    public function test_a_lockout_cannot_be_skipped_by_requesting_a_new_code(): void
    {
        $service = $this->otp();
        $service->issue('9100000041');

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $service->verify('9100000041', '000000');
        }

        $this->travel(2)->minutes();
        $this->assertSame(OtpService::BLOCKED_NOW, $service->issue('9100000041'));

        $this->travel(14)->minutes();
        $this->assertSame(OtpService::ISSUED, $service->issue('9100000041'));
    }

    public function test_changing_number_is_capped_per_account(): void
    {
        $user = $this->user('public', ['mobile_verified_at' => null]);

        foreach (['9100000051', '9100000052', '9100000053'] as $number) {
            $this->actingAs($user)->post('/mobile/number', ['mobile' => $number, 'current_password' => 'password'])->assertSessionHas('status');
        }

        $this->actingAs($user)->post('/mobile/number', ['mobile' => '9100000054', 'current_password' => 'password'])->assertSessionHas('error');
        $this->assertSame('9100000053', $user->fresh()->mobile);
    }

    // ---- H10: deletion

    public function test_deactivating_keeps_every_record_and_blocks_login(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();
        $application = $this->application($citizen);

        $this->actingAs($admin)->delete("/admin/users/{$citizen->id}")->assertSessionHasErrors('reason');
        $this->assertNull($citizen->fresh()->deactivated_at);

        $this->actingAs($admin)->delete("/admin/users/{$citizen->id}", ['reason' => 'Requested by the account holder'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $citizen->id]);
        $this->assertDatabaseHas('notary_requests', ['id' => $application->id, 'deleted_at' => null]);
        $this->assertSame('Requested by the account holder', AuditLog::where('action', 'admin.user_deactivated')->first()->meta['reason']);

        auth()->guard('web')->logout();
        $this->postJson('/custom-login', ['mobile' => $citizen->email, 'password' => 'password'])->assertStatus(401);

        $this->actingAs($admin)->post("/admin/users/{$citizen->id}/reactivate")->assertRedirect();
        $this->assertNull($citizen->fresh()->deactivated_at);
    }

    public function test_a_session_ends_when_the_account_is_deactivated(): void
    {
        $citizen = $this->user();

        $this->actingAs($citizen)->get('/dashboard')->assertOk();

        $citizen->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($citizen->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_admin_cannot_remove_a_document_from_a_decided_application(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $application = $this->application($this->user(), ['status' => 'completed']);
        $document = Document::create(['request_id' => $application->id, 'title' => 'T', 'original_file' => 'documents/a.pdf']);

        $this->actingAs($this->user('admin'))->delete("/admin/documents/{$document->id}", ['reason' => 'Trying to remove'])->assertSessionHas('error');

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_purge_is_a_dry_run_by_default_and_respects_retention(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::disk(Document::PRIVATE_DISK)->put('documents/old.pdf', 'x');
        Storage::disk(Document::PRIVATE_DISK)->put('documents/new.pdf', 'x');

        $application = $this->application($this->user());
        $old = Document::create(['request_id' => $application->id, 'title' => 'Old', 'original_file' => 'documents/old.pdf']);
        $new = Document::create(['request_id' => $application->id, 'title' => 'New', 'original_file' => 'documents/new.pdf']);
        $old->delete();
        $this->travel(400)->days();
        $new->delete();

        $this->artisan('enotary:purge-expired')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertDatabaseHas('documents', ['id' => $old->id]);
        Storage::disk(Document::PRIVATE_DISK)->assertExists('documents/old.pdf');

        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseMissing('documents', ['id' => $old->id]);
        Storage::disk(Document::PRIVATE_DISK)->assertMissing('documents/old.pdf');
        $this->assertDatabaseHas('documents', ['id' => $new->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.purged', 'model_id' => $old->id]);
    }

    // ---- Account protection

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->user();
        $payload = ['name' => $user->name, 'email' => $user->email, 'mobile' => $user->mobile, 'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'];

        $this->actingAs($user)->put('/user/profile', $payload)->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)->put('/user/profile', $payload + ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_changing_a_password_ends_sessions_elsewhere(): void
    {
        $this->withMiddleware(AuthenticateSession::class);
        $user = $this->user();

        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'password'])->assertOk();
        $this->get('/dashboard')->assertOk();

        // The same as a password change made from another device.
        $user->forceFill(['password' => Hash::make('Changed-elsewhere1')])->save();
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertRedirect();
        $this->assertGuest();
    }

    public function test_one_account_is_rate_limited_across_addresses(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
                ->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
            ->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_admin_edit_clears_verification_it_cannot_vouch_for(): void
    {
        $target = $this->user();

        $this->actingAs($this->user('admin'))->put("/admin/users/{$target->id}", [
            'name' => $target->name, 'email' => 'changed@example.test', 'mobile' => '9300009999', 'role' => 'public',
        ])->assertRedirect();

        $this->assertNull($target->fresh()->email_verified_at);
        $this->assertNull($target->fresh()->mobile_verified_at);
    }

    public function test_pending_notary_sees_no_links_that_would_be_refused(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null]);

        // Module 1: a page view goes to the waiting page instead of a bare 403.
        $this->actingAs($pending)->get('/notary/dashboard')->assertRedirect(route('notary.pending'));

        $this->actingAs($pending)->get(route('notary.pending'))->assertOk()
            ->assertSee('waiting for administrator approval')
            ->assertDontSee(route('notary.requests'), false);
    }

    // ---- Reminders and delivery

    public function test_reminders_go_once_and_only_for_confirmed_appointments_within_a_day(): void
    {
        Notification::fake();
        $citizen = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($citizen, ['status' => 'assigned', 'notary_id' => $notary->id]);

        $make = fn (string $status, $when) => Appointment::create(['request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary->id, 'status' => $status, 'scheduled_at' => $when]);

        $due = $make('confirmed', now()->addHours(5));
        $make('requested', now()->addHours(5));
        $make('confirmed', now()->addDays(3));

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 1')->assertSuccessful();
        Notification::assertSentToTimes($citizen, PortalNotification::class, 1);
        Notification::assertSentToTimes($notary, PortalNotification::class, 1);
        $this->assertNotNull($due->fresh()->reminded_at);

        $this->artisan('enotary:appointment-reminders')->expectsOutputToContain('Reminders sent: 0');
    }

    public function test_appointment_time_entered_in_ist_is_stored_in_utc_and_shown_in_ist(): void
    {
        Notification::fake();
        $citizen = $this->user();
        $application = $this->application($citizen);
        $ist = now()->addDays(2)->setTimezone('Asia/Kolkata')->setTime(10, 30);

        $this->actingAs($citizen)->post('/appointments', [
            'request_id' => $application->id, 'scheduled_at' => $ist->format('Y-m-d H:i'), 'mode' => 'video',
        ])->assertSessionHas('status');

        $stored = Appointment::firstOrFail();
        $this->assertSame('05:00', $stored->scheduled_at->format('H:i'));
        $this->assertSame('10:30', $stored->scheduled_at->ist('H:i'));
        $this->actingAs($citizen)->get('/appointments')->assertSee($ist->format('d M Y').' 10:30');
    }

    public function test_delivery_page_never_calls_a_queued_email_delivered_and_can_retry(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Notifications\\PortalNotification', 'data' => ['command' => 'x']]),
            'exception' => "RuntimeException: SMTP refused\n#0 trace with secrets", 'failed_at' => now(),
        ]);

        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/admin/delivery')->assertOk()
            ->assertSee('It is not delivered')->assertSee('SMTP refused')->assertDontSee('trace with secrets');

        $this->actingAs($this->user())->get('/admin/delivery')->assertForbidden();

        $this->actingAs($admin)->post('/admin/delivery/00000000-0000-4000-8000-000000000000/retry')->assertNotFound();

        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]);

        $this->actingAs($admin)->post('/admin/delivery/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/retry')->assertSessionHas('status');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.delivery_retried', 'user_id' => $admin->id]);
    }

    // ---- QR states

    public function test_a_removed_record_shows_as_unavailable_with_no_details(): void
    {
        $application = $this->application($this->user(), ['status' => 'completed']);
        Storage::fake('local');
        Storage::disk('local')->put('documents/x.pdf', 'content');
        $document = Document::create(['request_id' => $application->id, 'title' => 'T', 'original_file' => 'documents/x.pdf', 'file_hash' => hash('sha256', 'content'), 'document_number' => 'REC-1', 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);

        $this->get('/verify/ABCDEFGHJKLMNPQR')->assertSee('This record exists and is current.');

        $document->delete();

        $this->get('/verify/ABCDEFGHJKLMNPQR')->assertSee('This record is no longer available.')->assertDontSee('REC-1');
    }

    public function test_qr_links_use_the_configured_application_url(): void
    {
        config(['app.url' => 'https://enotary.example']);
        $document = new Document(['verification_code' => 'ABCDEFGHJKLMNPQR']);

        $this->assertSame('https://enotary.example/verify/ABCDEFGHJKLMNPQR', $document->verificationUrl());
    }

    // ---- PDFs

    public function test_summary_pdf_is_authorised_and_carries_the_record(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['purpose' => 'Purchase of a house']);

        $response = $this->actingAs($owner)->get("/applications/{$application->id}/summary.pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->actingAs($this->user())->get("/applications/{$application->id}/summary.pdf")->assertForbidden();
        $this->actingAs($this->user('notary'))->get("/applications/{$application->id}/summary.pdf")->assertForbidden();
        $this->actingAs($this->user('admin'))->get("/applications/{$application->id}/summary.pdf")->assertOk();
    }

    public function test_audit_pdf_is_admin_only(): void
    {
        $this->actingAs($this->user())->get('/admin/audit-logs/export.pdf')->assertForbidden();
        $this->actingAs($this->user('notary'))->get('/admin/audit-logs/export.pdf')->assertForbidden();

        $response = $this->actingAs($this->user('admin'))->get('/admin/audit-logs/export.pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_times_are_shown_in_indian_time(): void
    {
        $this->assertSame('31 Oct 2026, 05:30', Carbon::parse('2026-10-31 00:00:00', 'UTC')->ist());
    }
}
