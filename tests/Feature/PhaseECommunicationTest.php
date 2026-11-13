<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\Platform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The provider is faked, so nothing here reaches MSG91. */
class PhaseECommunicationTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
    }

    private function fakeAccepted(): void
    {
        Http::fake([SmsService::ENDPOINT => Http::response(['type' => 'success', 'request_id' => 'abcdef123456'])]);
    }

    private function paidRequests(): int
    {
        return count(Http::recorded(fn (Request $r) => $r->url() === SmsService::ENDPOINT));
    }

    public function test_a_budget_of_one_lets_exactly_one_paid_request_out(): void
    {
        config(['services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-a']);
        $this->fakeAccepted();
        $sms = app(SmsService::class);

        $this->assertTrue($sms->send(self::NUMBER, '123456'));
        $this->assertFalse($sms->send(self::NUMBER, '123456'));

        $this->assertSame('send_budget_spent', $sms->lastError);
        $this->assertSame(1, $this->paidRequests());
    }

    public function test_a_failed_paid_attempt_still_uses_the_budget(): void
    {
        config(['services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-a']);
        Http::fake([SmsService::ENDPOINT => fn () => throw new \RuntimeException('timeout')]);
        $sms = app(SmsService::class);

        $this->assertFalse($sms->send(self::NUMBER, '123456'));
        $this->assertFalse($sms->send(self::NUMBER, '123456'));

        $this->assertSame('send_budget_spent', $sms->lastError);
    }

    public function test_a_new_approval_id_gets_its_own_budget(): void
    {
        config(['services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-a']);
        $this->fakeAccepted();
        app(SmsService::class)->send(self::NUMBER, '123456');

        config(['services.msg91.budget_id' => 'approval-b']);

        $this->assertTrue(app(SmsService::class)->send(self::NUMBER, '123456'));
        $this->assertSame(2, $this->paidRequests());
    }

    public function test_a_refused_number_does_not_use_the_budget(): void
    {
        config(['services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-a', 'services.outbound.sms' => '9000000000']);
        $this->fakeAccepted();

        $this->assertFalse(app(SmsService::class)->send(self::NUMBER, '123456'));
        $this->assertNull(Cache::get(SmsService::BUDGET_CACHE.'approval-a'));
        $this->assertSame(0, $this->paidRequests());
    }

    public function test_no_budget_means_a_normal_site_is_not_limited_by_it(): void
    {
        $this->fakeAccepted();
        $sms = app(SmsService::class);

        $this->assertTrue($sms->send(self::NUMBER, '123456'));
        $this->assertTrue($sms->send(self::NUMBER, '123456'));
    }

    public function test_a_spent_budget_leaves_no_usable_code(): void
    {
        config(['services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-a']);
        Cache::put(SmsService::BUDGET_CACHE.'approval-a', 1, 600);
        $this->fakeAccepted();

        $this->assertSame(OtpService::SEND_FAILED, app(OtpService::class)->issue(self::NUMBER, '127.0.0.1'));
        $this->assertSame(0, $this->paidRequests());
    }

    public function test_the_account_check_reports_a_balance_and_never_prints_the_key(): void
    {
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response('42.5')]);

        $this->assertSame(0, Artisan::call('enotary:sms-status', ['--account' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Account state: ok, OTP route balance: 42.5', $output);
        $this->assertStringNotContainsString('test-key', $output);
        $this->assertSame(0, $this->paidRequests());
    }

    public function test_the_account_check_fails_on_an_ip_refusal(): void
    {
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '418', 'msgType' => 'error'])]);

        $this->assertSame(1, Artisan::call('enotary:sms-status', ['--account' => true]));
        $this->assertStringContainsString('Account state: ip_blocked', Artisan::output());
    }

    public function test_the_account_check_never_reads_a_permission_refusal_as_ok(): void
    {
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '204', 'msgType' => 'error'])]);

        $this->assertSame(1, Artisan::call('enotary:sms-status', ['--account' => true]));
        $this->assertStringContainsString('Account state: no_permission', Artisan::output());
    }

    public function test_the_account_check_never_reads_an_unlisted_answer_as_ok(): void
    {
        Http::fake([SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '999', 'msgType' => 'error'])]);

        $this->assertSame(['state' => 'unknown', 'balance' => null], app(SmsService::class)->accountReport());
    }

    public function test_a_balance_permission_refusal_leaves_the_send_to_msg91(): void
    {
        config(['services.msg91.preflight' => true]);
        Cache::flush();
        Http::fake([
            SmsService::BALANCE_ENDPOINT.'*' => Http::response(['msg' => '204', 'msgType' => 'error']),
            SmsService::ENDPOINT => Http::response(['type' => 'error', 'message' => 'Authkey has no permission to send an sms', 'code' => '204']),
        ]);
        $sms = app(SmsService::class);

        $this->assertFalse($sms->send(self::NUMBER, '123456'));
        $this->assertStringContainsString('no permission', (string) $sms->lastError);
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function missingLimits(): array
    {
        return [
            'neither, as on the Apache site' => [null, null],
            'no allowlist' => [null, 5],
            'no budget' => [self::NUMBER, null],
            'zero budget' => [self::NUMBER, 0],
            'blank allowlist' => [' , ', 5],
            'budget is not a number' => [self::NUMBER, 'many'],
        ];
    }

    #[DataProvider('missingLimits')]
    public function test_a_real_send_without_both_limits_never_reaches_msg91(mixed $allowlist, mixed $budget): void
    {
        config(['services.msg91.enforce_limits' => true, 'services.msg91.preflight' => true, 'services.outbound.sms' => $allowlist, 'services.msg91.send_budget' => $budget]);
        Http::fake();
        $sms = app(SmsService::class);

        $this->assertFalse($sms->send(self::NUMBER, '123456'));
        $this->assertSame('blocked_no_allowlist_or_budget', $sms->lastError);
        Http::assertNothingSent();
    }

    public function test_a_locked_send_is_recorded_as_blocked_and_leaves_no_usable_code(): void
    {
        config(['services.msg91.enforce_limits' => true]);
        Http::fake();

        $this->assertSame(OtpService::SEND_FAILED, app(OtpService::class)->issue(self::NUMBER));

        Http::assertNothingSent();
        $this->assertSame('blocked', DB::table('message_deliveries')->where('channel', 'sms')->value('status'));
        $this->assertFalse(app(OtpService::class)->status(self::NUMBER)['active']);
    }

    public function test_the_paid_test_command_is_locked_too(): void
    {
        config(['services.msg91.enforce_limits' => true]);
        Http::fake();

        $this->assertSame(1, Artisan::call('enotary:sms-test', ['mobile' => self::NUMBER, '--force' => true]));
        Http::assertNothingSent();
    }

    public function test_a_limited_real_send_goes_ahead_once_and_only_to_the_listed_number(): void
    {
        config(['services.msg91.enforce_limits' => true, 'services.outbound.sms' => self::NUMBER, 'services.msg91.send_budget' => 1, 'services.msg91.budget_id' => 'approval-guard']);
        $this->fakeAccepted();
        $sms = app(SmsService::class);

        $this->assertFalse($sms->send('9000000000', '123456'));
        $this->assertTrue($sms->send(self::NUMBER, '123456'));
        $this->assertFalse($sms->send(self::NUMBER, '123456'));
        $this->assertSame(1, $this->paidRequests());
    }

    public function test_the_mock_site_needs_no_budget(): void
    {
        config(['services.msg91.enforce_limits' => true, 'services.msg91.endpoint' => 'http://127.0.0.1:8003/otp']);

        $this->assertTrue(SmsService::limitsInPlace());
    }

    public function test_the_lock_cannot_be_turned_off_outside_the_test_suite(): void
    {
        config(['services.msg91.enforce_limits' => false]);
        $this->app['env'] = 'production';

        $this->assertFalse(SmsService::limitsInPlace());
    }

    public function test_a_stand_in_address_does_not_unlock_production(): void
    {
        config(['services.msg91.enforce_limits' => true, 'services.msg91.endpoint' => 'http://127.0.0.1:8003/otp']);
        $this->app['env'] = 'production';

        $this->assertFalse(SmsService::limitsInPlace());
    }

    public function test_status_and_security_page_show_the_lock(): void
    {
        config(['services.msg91.enforce_limits' => true]);

        Artisan::call('enotary:sms-status');
        $this->assertStringContainsString('LOCKED', Artisan::output());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.security.index'))->assertOk()->assertSee('Locked, no limits set');
    }

    public function test_every_otp_message_uses_the_configured_expiry(): void
    {
        config(['services.msg91.otp_ttl' => 7]);

        $this->post(route('password.email'), ['identifier' => self::NUMBER]);
        $this->get(route('password.request'))->assertOk()->assertSee('The code expires in 7 minutes.');

        $citizen = User::factory()->create(['role' => 'public', 'mobile' => self::NUMBER, 'email_verified_at' => now(), 'mobile_verified_at' => null]);
        $this->actingAs($citizen);
        $profile = view('user.profile', ['user' => $citizen])->render();

        $this->assertStringContainsString('Codes expire after 7 minutes', $profile);
        $this->assertStringContainsString('within 7 minutes', implode(' ', (new \App\Notifications\PasswordResetCodeNotification('123456', now()->addMinutes(7)))->toMail($citizen)->viewData['introLines']));
    }

    #[DataProvider('smsGates')]
    public function test_the_gate_allows_sms_only_when_limited(?string $key, string $numbers, ?int $budget, bool $passes): void
    {
        config(['services.msg91.auth_key' => $key, 'services.outbound.sms' => $numbers, 'services.msg91.send_budget' => $budget]);

        Artisan::call('enotary:local-gmail-check', ['--json' => true]);
        $failures = json_decode(Artisan::output(), true)['failures'];

        $this->assertSame(! $passes, in_array('SMS must be off, or limited to one approved number with a send budget.', $failures, true));
    }

    public static function smsGates(): array
    {
        return [
            'off'            => [null, '', null, true],
            'one and budget' => ['k', '9876543210', 1, true],
            'no number'      => ['k', '', 1, false],
            'two numbers'    => ['k', '9876543210,9876543211', 1, false],
            'no budget'      => ['k', '9876543210', null, false],
            'zero budget'    => ['k', '9876543210', 0, false],
        ];
    }

    public function test_the_scheduler_records_its_heartbeat_and_the_security_page_shows_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $this->assertFalse(Cache::has(Platform::SCHEDULER_HEARTBEAT));
        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()->assertSee('Scheduler');

        Artisan::call('schedule:run');

        $this->assertTrue(Cache::has(Platform::SCHEDULER_HEARTBEAT));
    }

    public function test_a_job_held_on_another_queue_does_not_read_as_a_stopped_worker(): void
    {
        config(['queue.connections.database.queue' => 'local_gmail']);
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()
            ->assertViewHas('queue', fn ($q) => $q['waiting'] === 0 && $q['held'] === 1 && ! $q['stuck'])
            ->assertSee('Held on Other Queues');
    }

    public function test_an_old_job_on_the_active_queue_still_raises_the_warning(): void
    {
        config(['queue.connections.database.queue' => 'local_gmail']);
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        DB::table('jobs')->insert(['queue' => 'local_gmail', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()
            ->assertViewHas('queue', fn ($q) => $q['waiting'] === 1 && $q['held'] === 0 && $q['stuck']);
    }

    public function test_the_expected_jobs_are_scheduled(): void
    {
        // Full schedule (scheduler safe mode OFF): every job is defined.
        $full = new Schedule;
        \App\Console\ScheduledTasks::register($full, false);
        $commands = collect($full->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        foreach (['enotary:appointment-reminders', 'enotary:clear-expired-codes', 'enotary:clear-stale-drafts', 'queue:prune-failed'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }

        // The live default (safe mode ON, owner-approved 2026-11-10) registers none of the cleanup tasks;
        // appointment reminders are the one email task it allows (owner-approved 2026-11-11).
        $live = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
        foreach (['enotary:clear-expired-codes', 'enotary:clear-stale-drafts', 'queue:prune-failed', 'enotary:expire-stale-requests'] as $command) {
            $this->assertStringNotContainsString($command, $live);
        }
        $this->assertStringContainsString('enotary:appointment-reminders', $live);
    }
}
