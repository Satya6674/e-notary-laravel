<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Jobs\RecordPasswordResetFailure;
use App\Jobs\SendPasswordResetCode;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Providers\AppServiceProvider;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Module 8: password reset and login. Neither the wording nor the timing of Forgot Password says
 * whether an email address or number has an account, the lookup and the emailed code happen after
 * the response, the login limit counts one account however its number is written, queued jobs hold
 * no readable secret, and the login form posts. No SMS is ever attempted (the provider stand-in must
 * stay unused) and no email leaves: mail is the in-memory array mailer. Every account is synthetic.
 */
class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'demo-pass-123';

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsService::class, fn ($mock) => $mock->shouldReceive('send')->andReturnUsing(function (string $mobile, string $code) {
            $this->sent[] = $code;

            return true;
        }));
    }

    private function user(string $mobile, array $extra = []): User
    {
        $user = User::create([
            'name' => 'Demo '.$mobile, 'email' => "demo{$mobile}@example.test", 'mobile' => $mobile,
            'password' => Hash::make(self::PASSWORD), 'role' => 'public', 'email_verified_at' => now(),
        ]);

        // array_merge, so a value in $extra (such as null) replaces the default.
        $user->forceFill(array_merge(['mobile_verified_at' => now()], $extra))->save();

        return $user;
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        return $count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
    }

    // ---- 1: the same answer, whatever the number

    public function test_step_one_answers_the_same_for_every_kind_of_number(): void
    {
        Queue::fake();
        $this->user('9488800001');                                        // may reset by SMS
        $this->user('9488800002', ['mobile_verified_at' => null]);         // number never proven
        $this->user('9488800003', ['role' => 'admin']);                    // admins are email-only

        $answers = [];
        foreach (['9488800001', '9488800002', '9488800003', '9488800004'] as $mobile) {
            $response = $this->from(route('password.request'))->post(route('password.email'), ['identifier' => $mobile]);

            $answers[$mobile] = [$response->status(), $response->headers->get('Location'), session('status'), session('errors'), session('reset.identifier') === 'mobile:'.$mobile];
            session()->flush();
        }

        $this->assertCount(1, array_unique(array_map('serialize', $answers)), 'the answer differs by number');

        // Nothing account-dependent ran in the request: one identical job per number, no code, no audit.
        Queue::assertPushed(SendPasswordResetCode::class, 4);
        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame(0, AuditLog::where('action', 'auth.password_reset_refused')->count());
        $this->assertSame([], $this->sent);
    }

    private function mailed(): int
    {
        return count(app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_the_job_emails_a_code_only_to_an_eligible_account(): void
    {
        $eligible = $this->user('9488800011');
        $unverified = $this->user('9488800012', ['email_verified_at' => null]);

        foreach (['mobile:9488800011', 'mobile:9488800012', 'mobile:9488800014'] as $identifier) {
            (new SendPasswordResetCode($identifier, '198.51.100.9'))->handle(app(OtpService::class), app(\App\Services\DeliveryLog::class));
        }

        $this->assertSame(1, $this->mailed());
        $this->assertSame([], $this->sent, 'never by SMS');
        $this->assertSame(1, DB::table('otp_verifications')->where('mobile', OtpService::emailKey($eligible))->count());
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', OtpService::emailKey($unverified))->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset_refused')->where('model_id', $unverified->id)->count());
    }

    /** A live reset code for this account, as the job would store it. */
    private function plantCode(User $user, string $code, array $extra = []): void
    {
        DB::table('otp_verifications')->insert(array_merge([
            'mobile' => OtpService::emailKey($user),
            'otp' => OtpService::FLOW_PASSWORD_RESET_EMAIL.'|'.Hash::make('password-reset-email:'.sha1(strtolower($user->email)).':'.$code),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'blocked_until' => null, 'ip_address' => null, 'created_at' => now(),
        ], $extra));
    }

    public function test_step_two_answers_the_same_for_every_failure(): void
    {
        Queue::fake();
        $this->plantCode($this->user('9488800021'), '111111');
        // Locked out from another session: still refused, but it must not say so differently.
        $this->plantCode($this->user('9488800022'), '222222', ['attempts' => 5, 'blocked_until' => now()->addMinutes(15)]);

        $answers = [];
        foreach (['9488800021' => 'wrong code', '9488800022' => 'locked out', '9488800023' => 'no account'] as $mobile => $case) {
            $response = $this->withSession(['reset.identifier' => 'mobile:'.$mobile, 'reset.requested_at' => now()->timestamp])
                ->from(route('password.request'))->post(route('password.verify.otp'), ['otp' => '999999']);

            $answers[$case] = [$response->status(), $response->headers->get('Location'), session('errors')?->get('otp')];
            session()->flush();
        }

        $this->assertCount(1, array_unique(array_map('serialize', $answers)), 'the answer differs by case: '.json_encode($answers));
        $this->assertSame([ForgotPasswordController::CODE_INVALID], $answers['no account'][2]);
        Queue::assertPushed(RecordPasswordResetFailure::class, 3);
    }

    // ---- 1 and 2: the time taken does not say either

    public function test_step_one_takes_the_same_time_with_or_without_an_account(): void
    {
        // A realistic hashing cost, so a hash done in the request would stand far above noise.
        config(['hashing.bcrypt.rounds' => 11]);
        $kernel = $this->app->make(Kernel::class);
        $times = ['registered' => [], 'unregistered' => []];

        for ($i = 10; $i < 22; $i++) {
            $this->user('94888001'.$i);

            // Interleaved, a fresh number each time, so no cooldown hides any work.
            foreach (['registered' => '94888001'.$i, 'unregistered' => '94888002'.$i] as $kind => $mobile) {
                // Its own address each time: the route allows 5 requests a minute per address.
                $request = Request::create(route('password.email'), 'POST', ['identifier' => $mobile], [], [], ['REMOTE_ADDR' => '203.0.113.'.($i * 2 + ($kind === 'registered' ? 0 : 1))]);

                $started = hrtime(true);
                $response = $kernel->handle($request);
                $times[$kind][] = (hrtime(true) - $started) / 1e6;

                // Everything deferred runs here, after the response, as on a real server.
                $kernel->terminate($request, $response);
            }
        }

        $registered = $this->median($times['registered']);
        $unregistered = $this->median($times['unregistered']);

        fwrite(STDERR, sprintf("
[timing] step 1 median: %.1f ms with an account, %.1f ms without
", $registered, $unregistered));

        // The work after the response did happen: one emailed code per registered number, no SMS.
        $this->assertSame(12, $this->mailed());
        $this->assertSame([], $this->sent);
        $this->assertLessThan(15, abs($registered - $unregistered), sprintf('median %.1f ms with an account, %.1f ms without', $registered, $unregistered));
    }

    public function test_step_two_takes_the_same_time_with_or_without_an_account(): void
    {
        config(['hashing.bcrypt.rounds' => 11]);
        $times = ['registered' => [], 'unregistered' => []];

        for ($i = 10; $i < 22; $i++) {
            $registered = '94888003'.$i;
            // A live code for the registered account, so its check does real bcrypt work.
            $this->plantCode($this->user($registered), '123456');

            foreach (['registered' => $registered, 'unregistered' => '94888004'.$i] as $kind => $mobile) {
                // Its own address each time: the route allows 10 requests a minute per address.
                $session = $this->withSession(['reset.identifier' => 'mobile:'.$mobile, 'reset.requested_at' => now()->timestamp])
                    ->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i * 2 + ($kind === 'registered' ? 0 : 1))]);

                $started = hrtime(true);
                $session->post(route('password.verify.otp'), ['otp' => '654321'])->assertSessionHasErrors('otp');
                $times[$kind][] = (hrtime(true) - $started) / 1e6;
            }
        }

        $registered = $this->median($times['registered']);
        $unregistered = $this->median($times['unregistered']);

        fwrite(STDERR, sprintf("
[timing] step 2 median: %.1f ms with an account, %.1f ms without
", $registered, $unregistered));

        $this->assertLessThan(15, abs($registered - $unregistered), sprintf('median %.1f ms with an account, %.1f ms without', $registered, $unregistered));
    }

    // ---- 3: one login limit per account, however the number is written

    public function test_the_login_limit_counts_every_way_of_writing_one_number(): void
    {
        $this->user('9488800031');
        $formats = ['9488800031', '+91 94888 00031', '094888-00031', '+91-9488800031', ' 9488800031 '];

        // Twenty wrong passwords, rotating formats and addresses, so only the account limit applies.
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i + 1)])
                ->postJson(route('login.submit'), ['mobile' => $formats[$i % count($formats)], 'password' => 'wrong-'.$i])
                ->assertStatus(401);
        }

        foreach ($formats as $n => $written) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.(100 + $n)])
                ->postJson(route('login.submit'), ['mobile' => $written, 'password' => self::PASSWORD])
                ->assertStatus(429);
        }

        // Another account is unaffected.
        $this->user('9488800032');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->postJson(route('login.submit'), ['mobile' => '9488800032', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_one_identity_for_every_way_of_writing_it(): void
    {
        $this->assertCount(1, array_unique(array_map([AppServiceProvider::class, 'loginIdentity'], ['9488800031', '+91 94888 00031', '094888-00031', '+91-9488800031'])));
        $this->assertCount(1, array_unique(array_map([AppServiceProvider::class, 'loginIdentity'], ['Demo@Example.TEST', ' demo@example.test '])));
        $this->assertNotSame(AppServiceProvider::loginIdentity('9488800031'), AppServiceProvider::loginIdentity('9488800032'));
    }

    // ---- 4: no readable secret waits in the queue

    public function test_a_queued_reset_email_holds_no_readable_token(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->user('9488800041');
        $token = 'demo-reset-token-'.bin2hex(random_bytes(16));

        $user->notify(new ResetPasswordNotification($token));

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($user->email, $payload);

        // It is encrypted, not dropped: the worker can still read it with the application key.
        $this->assertStringContainsString($token, Crypt::decryptString(json_decode($payload, true)['data']['command']));
        $this->assertSame(1, MessageDelivery::where('status', MessageDelivery::QUEUED)->count());
    }

    public function test_the_reset_request_by_email_queues_no_token_no_code_and_no_readable_address(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->user('9488800042');

        $this->post(route('password.email'), ['identifier' => $user->email])->assertRedirect();

        $this->assertSame(0, DB::table('password_resets')->count(), 'the public form makes no link token');
        $this->assertSame(0, DB::table('otp_verifications')->count(), 'no code exists until the job runs');
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringContainsString('SendPasswordResetCode', $payload);
        $this->assertStringContainsString($user->email, Crypt::decryptString(json_decode($payload, true)['data']['command']));
    }

    public function test_a_queued_mobile_reset_request_holds_no_code_and_no_readable_number(): void
    {
        config(['queue.default' => 'database']);
        $this->user('9488800043');

        $this->post(route('password.email'), ['identifier' => '9488800043'])->assertRedirect();

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('9488800043', $payload);
        $this->assertStringContainsString('SendPasswordResetCode', $payload);
        // No code exists until the job runs, so none can be in the queue.
        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame([], $this->sent);
    }

    // ---- The login form posts, with its CSRF token

    public function test_the_login_form_posts_with_a_csrf_token(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form id="loginForm" method="POST" action="'.preg_quote(route('login.submit'), '/').'"/', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->get(route('login.submit'))->assertStatus(405);
    }

    public function test_login_without_a_valid_csrf_token_is_refused_outside_tests(): void
    {
        $this->user('9488800051');
        // CSRF checking is skipped while unit tests run, so this request runs as the local site does.
        $this->app['env'] = 'local';

        $this->post(route('login.submit'), ['mobile' => '9488800051', 'password' => self::PASSWORD])->assertStatus(419);
        $this->assertGuest();

        $this->withSession(['_token' => 'known-demo-token'])
            ->post(route('login.submit'), ['_token' => 'known-demo-token', 'mobile' => '9488800051', 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_no_sms_left_the_application(): void
    {
        $this->user('9488800061');
        $this->post(route('password.email'), ['identifier' => '9488800061'])->assertRedirect();

        Http::assertNothingSent();
    }
}
