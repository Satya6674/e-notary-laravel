<?php

namespace Tests\Feature;

use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Module 6: each OTP flow keeps its own codes, attempts, lock-out, cooldown and expiry,
 * without a schema change. The paid daily caps stay shared, and every limit is taken
 * atomically. No SMS leaves: the provider is a stand-in that records the code.
 */
class OtpFlowIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9466600001';

    private const FLOWS = [OtpService::FLOW_MOBILE_VERIFICATION, OtpService::FLOW_PASSWORD_RESET, OtpService::FLOW_MOBILE_CHANGE, OtpService::PURPOSE_RECOVERY];

    /** @var list<string> */
    private array $sent = [];

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsService::class, fn ($mock) => $mock->shouldReceive('send')->andReturnUsing(function (string $mobile, string $code) {
            $this->sent[] = $code;

            return true;
        }));

        $this->otp = app(OtpService::class);
    }

    /** Issues a code for the flow and returns it, as the handset would receive it. */
    private function issue(string $flow, string $mobile = self::MOBILE): string
    {
        $this->assertSame(OtpService::ISSUED, $this->otp->issue($mobile, '198.51.100.7', $flow));

        return end($this->sent);
    }

    private function check(string $flow, string $code, string $mobile = self::MOBILE): string
    {
        return $flow === OtpService::PURPOSE_RECOVERY
            ? $this->otp->verifyRecoveryCode($mobile, $code)
            : $this->otp->verify($mobile, $code, false, $flow);
    }

    // ---- 1 and 2: separate purposes; a code never works in another flow

    public function test_a_code_from_one_flow_never_works_in_any_other(): void
    {
        $codes = [];
        foreach (self::FLOWS as $flow) {
            $codes[$flow] = $this->issue($flow);
        }

        foreach (self::FLOWS as $issuedFor) {
            foreach (self::FLOWS as $triedIn) {
                if ($issuedFor !== $triedIn && $codes[$issuedFor] !== $codes[$triedIn]) {
                    $this->assertSame(OtpService::INVALID, $this->check($triedIn, $codes[$issuedFor]), "{$issuedFor} code accepted in {$triedIn}");
                }
            }
        }

        // Each flow's own code still works, once.
        foreach (self::FLOWS as $flow) {
            $this->assertSame(OtpService::OK, $this->check($flow, $codes[$flow]), $flow);
            $this->assertSame(OtpService::EXPIRED, $this->check($flow, $codes[$flow]), "{$flow} reused");
        }
    }

    public function test_a_flow_with_no_code_of_its_own_accepts_nothing(): void
    {
        $code = $this->issue(OtpService::FLOW_PASSWORD_RESET);

        foreach ([OtpService::FLOW_MOBILE_VERIFICATION, OtpService::FLOW_MOBILE_CHANGE, OtpService::PURPOSE_RECOVERY] as $flow) {
            $this->assertSame(OtpService::EXPIRED, $this->check($flow, $code), $flow);
        }

        // Those refusals cost the password-reset code nothing.
        $this->assertSame(OtpService::MAX_ATTEMPTS, $this->otp->status(self::MOBILE, OtpService::FLOW_PASSWORD_RESET)['attempts_left']);
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_PASSWORD_RESET, $code));
    }

    // ---- 3: a new code supersedes only its own flow

    public function test_issuing_in_one_flow_keeps_every_other_flows_code_alive(): void
    {
        $reset = $this->issue(OtpService::FLOW_PASSWORD_RESET);
        $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);
        $this->issue(OtpService::FLOW_MOBILE_CHANGE);
        $this->issue(OtpService::PURPOSE_RECOVERY);

        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_PASSWORD_RESET, $reset));
    }

    public function test_a_new_code_replaces_the_older_one_in_the_same_flow(): void
    {
        $first = $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);
        $this->travel(61)->seconds();
        $second = $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);

        if ($first !== $second) {
            $this->assertSame(OtpService::INVALID, $this->check(OtpService::FLOW_MOBILE_VERIFICATION, $first));
        }
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_MOBILE_VERIFICATION, $second));
    }

    public function test_each_flow_expires_on_its_own_clock(): void
    {
        $reset = $this->issue(OtpService::FLOW_PASSWORD_RESET);
        $this->travel(8)->minutes();
        $verify = $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);
        $this->travel(3)->minutes();

        $this->assertSame(OtpService::EXPIRED, $this->check(OtpService::FLOW_PASSWORD_RESET, $reset));
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_MOBILE_VERIFICATION, $verify));
    }

    // ---- 4: attempts and lock-outs are per flow (BR-053)

    public function test_wrong_guesses_in_one_flow_never_lock_another(): void
    {
        $reset = $this->issue(OtpService::FLOW_PASSWORD_RESET);
        $verify = $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);
        $wrong = $reset === '000000' ? '000001' : '000000';

        // Anyone who knows the number can start a reset and guess wrong five times.
        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->check(OtpService::FLOW_PASSWORD_RESET, $wrong === $verify ? '999999' : $wrong);
        }

        $this->assertSame(OtpService::BLOCKED, $this->check(OtpService::FLOW_PASSWORD_RESET, $reset));
        $this->travel(61)->seconds();
        $this->assertSame(OtpService::BLOCKED_NOW, $this->otp->issue(self::MOBILE, null, OtpService::FLOW_PASSWORD_RESET));

        // The owner's other flows are untouched: full attempts, no lock-out, codes still issue.
        $status = $this->otp->status(self::MOBILE, OtpService::FLOW_MOBILE_VERIFICATION);
        $this->assertSame(OtpService::MAX_ATTEMPTS, $status['attempts_left']);
        $this->assertSame(0, $status['blocked']);
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_MOBILE_VERIFICATION, $verify));
        $this->issue(OtpService::FLOW_MOBILE_CHANGE);
    }

    // ---- 5: cooldown, hourly and daily limits

    public function test_the_cooldown_and_hourly_limit_are_kept_per_flow(): void
    {
        $this->issue(OtpService::FLOW_PASSWORD_RESET);
        $this->assertSame(OtpService::COOLDOWN, $this->otp->issue(self::MOBILE, null, OtpService::FLOW_PASSWORD_RESET));
        $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);

        for ($i = 1; $i < OtpService::MAX_PER_HOUR; $i++) {
            $this->travel(61)->seconds();
            $this->issue(OtpService::FLOW_PASSWORD_RESET);
        }

        $this->travel(61)->seconds();
        $this->assertSame(OtpService::HOURLY_LIMIT, $this->otp->issue(self::MOBILE, null, OtpService::FLOW_PASSWORD_RESET));
        $this->issue(OtpService::FLOW_MOBILE_CHANGE);
    }

    public function test_the_paid_daily_cap_is_shared_by_every_flow_on_a_number(): void
    {
        for ($i = 0; $i < OtpService::MAX_PER_DAY; $i++) {
            $this->issue(self::FLOWS[$i % count(self::FLOWS)]);
            $this->travel(61)->seconds();
        }

        foreach (self::FLOWS as $flow) {
            $this->assertSame(OtpService::DAILY_LIMIT, $this->otp->issue(self::MOBILE, null, $flow), $flow);
        }

        $this->assertCount(OtpService::MAX_PER_DAY, $this->sent);
    }

    public function test_a_request_that_finds_the_number_busy_stores_and_counts_nothing(): void
    {
        // Another request holds the number's lock, as during a real concurrent send.
        $held = Cache::lock('otp-issue:'.self::MOBILE, 15);
        $this->assertTrue($held->get());

        try {
            $this->assertSame(OtpService::COOLDOWN, $this->otp->issue(self::MOBILE, null, OtpService::FLOW_PASSWORD_RESET));
        } finally {
            $held->release();
        }

        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame(0, $this->otp->sentToday());
        $this->assertSame([], $this->sent);
    }

    public function test_a_refused_daily_limit_gives_back_what_it_took(): void
    {
        config(['services.msg91.daily_limit' => 2]);

        $this->issue(OtpService::FLOW_PASSWORD_RESET, '9466600011');
        $this->issue(OtpService::FLOW_PASSWORD_RESET, '9466600012');
        $this->assertSame(OtpService::DAILY_LIMIT, $this->otp->issue('9466600013', '198.51.100.7', OtpService::FLOW_PASSWORD_RESET));

        $day = now()->format('Ymd');
        $this->assertSame(2, $this->otp->sentToday());
        $this->assertSame(0, (int) Cache::get("otp-day:{$day}:num:9466600013"));
        $this->assertSame(2, (int) Cache::get("otp-day:{$day}:ip:198.51.100.7"));
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', '9466600013')->count());
    }

    // ---- 6: never the raw code

    public function test_no_code_is_ever_stored_only_its_flow_and_hash(): void
    {
        $codes = [];
        foreach (self::FLOWS as $flow) {
            $codes[$flow] = $this->issue($flow);
        }

        foreach (DB::table('otp_verifications')->pluck('otp') as $stored) {
            [$tag, $hash] = explode('|', $stored, 2);

            $this->assertContains($tag, self::FLOWS);
            $this->assertStringStartsWith('$2y$', $hash);
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $stored);
            }
            // The hash carries the flow's own prefix: a bare code does not match it, except
            // for mobile verification, whose prefix has always been empty.
            $this->assertSame($tag === OtpService::FLOW_MOBILE_VERIFICATION, Hash::check($codes[$tag], $hash));
        }
    }

    // ---- 7: rows written before flow tags keep their old behaviour

    public function test_untagged_rows_from_before_keep_their_old_behaviour(): void
    {
        $legacy = fn (string $hashed) => DB::table('otp_verifications')->insertGetId([
            'mobile' => self::MOBILE, 'otp' => Hash::make($hashed), 'expires_at' => now()->addMinutes(10),
            'attempts' => 0, 'ip_address' => null, 'created_at' => now(),
        ]);

        // A plain legacy code passed every SMS flow before, so it still does until it expires.
        $legacy('246810');
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_PASSWORD_RESET, '246810'));

        // A legacy recovery code passes recovery only, as before.
        DB::table('otp_verifications')->delete();
        $legacy('account-recovery:135790');
        $this->assertSame(OtpService::INVALID, $this->check(OtpService::FLOW_MOBILE_VERIFICATION, '135790'));
        $this->assertSame(OtpService::OK, $this->check(OtpService::PURPOSE_RECOVERY, '135790'));

        // A legacy lock-out still holds for every flow until it ends.
        DB::table('otp_verifications')->delete();
        DB::table('otp_verifications')->where('id', $legacy('111111'))->update(['blocked_until' => now()->addMinutes(15), 'attempts' => 5]);
        foreach (self::FLOWS as $flow) {
            $this->assertSame(OtpService::BLOCKED_NOW, $this->otp->issue(self::MOBILE, null, $flow), $flow);
        }

        // And it ages out on its own, after which the flows are fully separate.
        $this->travel(16)->minutes();
        $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);
    }

    // ---- Clearing, misuse, and the real provider

    public function test_clearing_one_flow_leaves_the_others(): void
    {
        $reset = $this->issue(OtpService::FLOW_PASSWORD_RESET);
        $this->issue(OtpService::FLOW_MOBILE_VERIFICATION);

        $this->otp->clear(self::MOBILE, OtpService::FLOW_MOBILE_VERIFICATION);
        $this->assertFalse($this->otp->status(self::MOBILE, OtpService::FLOW_MOBILE_VERIFICATION)['active']);
        $this->assertSame(OtpService::OK, $this->check(OtpService::FLOW_PASSWORD_RESET, $reset));

        $this->otp->clear(self::MOBILE);
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', self::MOBILE)->count());
    }

    public function test_an_unknown_flow_is_refused_outright(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->otp->issue(self::MOBILE, null, 'login');
    }

    public function test_the_real_provider_sends_nothing_in_this_environment(): void
    {
        $this->app->forgetInstance(SmsService::class);
        $this->app->offsetUnset(SmsService::class);
        $real = app(OtpService::class);

        $this->assertSame(OtpService::SEND_FAILED, $real->issue('9466600099', null, OtpService::FLOW_PASSWORD_RESET));
        Http::assertNothingSent();

        // Nobody received that code, so it is already expired.
        $this->assertFalse($real->status('9466600099', OtpService::FLOW_PASSWORD_RESET)['active']);
    }
}
