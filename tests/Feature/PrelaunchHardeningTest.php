<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetCode;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\OtpService;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pre-launch hardening: OTP wrong-guess carry-over and daily email caps, the email emergency stop,
 * production configuration checks, links in portal emails, the notification redirect, audit IPs from
 * queued jobs, the CSP and the profile page's form ids. Every account is synthetic.
 */
class PrelaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The test suite's own stop file (TestCase), never the live storage/framework/mail-stopped.
        @unlink(Platform::mailStopFile());

        parent::tearDown();
    }

    private function citizen(string $email = 'prelaunch.citizen@example.test', string $mobile = '9455500001'): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Prelaunch Person', 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('Demo-pass-123'),
            'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function wrong(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    public function test_wrong_guesses_carry_over_to_a_new_code(): void
    {
        $otp = app(OtpService::class);
        $user = $this->citizen();

        [, $first] = $otp->issueEmailCode($user);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(OtpService::INVALID, $otp->verifyEmailCode($user, $this->wrong($first)));
        }

        $this->travel(61)->seconds();
        [$result, $second] = $otp->issueEmailCode($user);
        $this->assertSame(OtpService::ISSUED, $result);

        // The fifth wrong guess overall locks, although it is the first against the new code.
        $this->assertSame(OtpService::INVALID, $otp->verifyEmailCode($user, $this->wrong($second)));
        $this->assertSame(OtpService::BLOCKED, $otp->verifyEmailCode($user, $second));
    }

    public function test_the_count_starts_again_after_a_successful_code(): void
    {
        $otp = app(OtpService::class);
        $user = $this->citizen();

        [, $first] = $otp->issueEmailCode($user);
        $otp->verifyEmailCode($user, $this->wrong($first));
        $this->assertSame(OtpService::OK, $otp->verifyEmailCode($user, $first));

        $this->travel(61)->seconds();
        [, $second] = $otp->issueEmailCode($user);
        for ($i = 0; $i < 4; $i++) {
            $otp->verifyEmailCode($user, $this->wrong($second));
        }
        $this->assertSame(OtpService::OK, $otp->verifyEmailCode($user, $second));
    }

    public function test_email_codes_have_a_daily_cap_per_account(): void
    {
        config(['accounts.email_code_daily_per_account' => 2]);
        $otp = app(OtpService::class);
        $user = $this->citizen();

        $this->assertSame(OtpService::ISSUED, $otp->issueEmailCode($user)[0]);
        $this->travel(61)->seconds();
        $this->assertSame(OtpService::ISSUED, $otp->issueEmailCode($user)[0]);
        $this->travel(61)->seconds();
        $this->assertSame(OtpService::DAILY_LIMIT, $otp->issueEmailCode($user)[0]);
        // The Forgot Password flow has its own counter.
        $this->assertSame(OtpService::ISSUED, $otp->issuePasswordResetCode($user)[0]);
    }

    public function test_email_codes_have_a_site_wide_daily_cap(): void
    {
        config(['accounts.email_code_daily_site' => 1]);
        $otp = app(OtpService::class);

        $this->assertSame(OtpService::ISSUED, $otp->issueEmailCode($this->citizen())[0]);
        $this->assertSame(OtpService::DAILY_LIMIT, $otp->issueEmailCode($this->citizen('second@example.test', '9455500002'))[0]);
        $this->assertSame(1, $otp->emailCodesSentToday());
    }

    public function test_email_codes_have_a_daily_cap_per_ip(): void
    {
        config(['accounts.email_code_daily_per_ip' => 1]);
        $otp = app(OtpService::class);

        $this->assertSame(OtpService::ISSUED, $otp->issueEmailCode($this->citizen(), '198.51.100.7')[0]);
        $this->assertSame(OtpService::DAILY_LIMIT, $otp->issueEmailCode($this->citizen('third@example.test', '9455500003'), '198.51.100.7')[0]);
    }

    public function test_sign_up_attempts_are_capped_per_ip_per_day(): void
    {
        config(['accounts.register_daily_per_ip' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/custom-register', ['name' => 'Probe', 'email' => "probe{$i}@example.test"])->assertStatus(422);
        }

        $this->postJson('/custom-register', ['name' => 'Probe', 'email' => 'probe9@example.test'])->assertStatus(429);
    }

    public function test_the_code_lifetime_is_clamped(): void
    {
        config(['services.msg91.otp_ttl' => 600]);
        $this->assertSame(15, OtpService::ttl());
        config(['services.msg91.otp_ttl' => 10]);
        $this->assertSame(10, OtpService::ttl());
    }

    public function test_the_emergency_stop_blocks_every_email_at_once_and_signals_the_worker(): void
    {
        $user = $this->citizen();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->artisan('enotary:mail-stop', ['action' => 'on'])->assertSuccessful();
        $this->assertTrue(Platform::mailStopped());
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));

        ['delivery' => $delivery] = $user->fresh()->sendEmailVerification('203.0.113.5');
        $this->assertSame(MessageDelivery::BLOCKED, $delivery);
        $this->assertSame('mail_stopped', MessageDelivery::latest('id')->first()->error);

        $this->artisan('enotary:mail-stop', ['action' => 'off'])->assertSuccessful();
        $this->assertFalse(Platform::mailStopped());
        $this->assertSame(1, AuditLog::where('action', 'system.mail_stopped')->count());
    }

    public function test_the_deploy_check_fails_on_the_local_configuration_and_passes_a_production_one(): void
    {
        $this->artisan('enotary:deploy-check')->assertFailed();

        $this->app['env'] = 'production';
        config([
            'app.debug' => false, 'app.url' => 'https://enotary.example.org', 'session.secure' => true, 'session.encrypt' => true,
            'accounts.admin_mfa_required' => true, 'queue.default' => 'database', 'services.msg91.auth_key' => null,
            'mail.default' => 'log', 'mail.from.address' => 'support@enotary.example.org',
            // On the MariaDB run the connection is the throwaway enotary_phpunit, which the check
            // rightly flags; present a production name (the open connection is not changed).
            'database.connections.'.config('database.default').'.database' => 'enotary_production',
        ]);

        $this->artisan('enotary:deploy-check')->assertSuccessful();
    }

    public function test_live_mail_refuses_a_loopback_app_url_in_production(): void
    {
        $this->assertTrue(Platform::privateHost('127.0.0.1'));
        $this->assertTrue(Platform::privateHost('[::1]'));
        $this->assertTrue(Platform::privateHost('192.168.1.10'));
        $this->assertTrue(Platform::privateHost('enotary.test'));
        $this->assertFalse(Platform::privateHost('enotary.example.org'));

        $this->app['env'] = 'production';
        config(['mail.live_recipients' => 'any', 'mail.from.address' => 'support@enotary.example.org', 'app.url' => 'https://127.0.0.1']);
        $this->assertSame('app_url_not_public', Platform::liveMailProblem());

        config(['app.url' => 'https://enotary.example.org']);
        $this->assertNull(Platform::liveMailProblem());
    }

    public function test_portal_email_buttons_use_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'https://enotary.example.org']);
        $notification = new PortalNotification('New ticket', 'A ticket arrived.', url('/admin/requests/5'));

        $this->assertSame('https://enotary.example.org/admin/requests/5', $notification->mailUrl());
        $person = $this->citizen();
        $this->assertSame('https://enotary.example.org/admin/requests/5', $notification->toMail($person)->viewData['actionUrl']);
        // The in-app copy keeps the URL as given.
        $this->assertSame(url('/admin/requests/5'), $notification->toArray($person)['url']);
    }

    public function test_a_notification_link_to_another_site_is_not_followed(): void
    {
        $user = $this->citizen();
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => PortalNotification::class, 'data' => ['title' => 't', 'body' => 'b', 'url' => 'https://phish.example.com/login']]);

        $this->actingAs($user)->get(route('notifications.read', $id))->assertRedirect(route('notifications.index'));
        $this->assertNotNull($user->notifications()->find($id)->read_at);

        $id2 = (string) Str::uuid();
        $user->notifications()->create(['id' => $id2, 'type' => PortalNotification::class, 'data' => ['title' => 't', 'body' => 'b', 'url' => url('/support')]]);
        $this->actingAs($user)->get(route('notifications.read', $id2))->assertRedirect(url('/support'));
    }

    public function test_audit_rows_written_by_the_reset_code_job_keep_the_requester_ip(): void
    {
        $user = $this->citizen();
        (new SendPasswordResetCode('email:'.strtolower($user->email), '203.0.113.77'))->handle(app(OtpService::class), app(\App\Services\DeliveryLog::class));

        $row = AuditLog::where('action', 'auth.password_reset_code_issued')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('203.0.113.77', $row->ip_address);
        $this->assertNull(AuditLog::$jobIp);
    }

    public function test_the_csp_forbids_plugins(): void
    {
        $this->get('/')->assertHeader('Content-Security-Policy')
            ->assertOk();
        $this->assertStringContainsString("object-src 'none'", $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_the_profile_page_has_no_duplicate_password_field_ids(): void
    {
        $html = $this->actingAs($this->citizen())->get(route('user.profile'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="f-current_password"'));
        $this->assertLessThanOrEqual(1, substr_count($html, 'id="f-mobile-current-password"'));
    }

    public function test_the_health_check_reports_measured_values_and_sends_nothing(): void
    {
        $this->artisan('enotary:health', ['--json' => true, '--backups' => storage_path('framework/testing')])
            ->expectsOutputToContain('"database"')
            ->run();

        $this->assertSame(0, MessageDelivery::count());
    }
}
