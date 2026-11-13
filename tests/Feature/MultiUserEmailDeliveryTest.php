<?php

namespace Tests\Feature;

use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Worker;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/** Reads real built messages from the in-memory mailer. No message leaves this process. */
class MultiUserEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'citizen.a@example.test';

    private const B = 'citizen.b@example.test';

    private const C = 'notary.c@example.test';

    private const MOBILES = [self::A => '9000000001', self::B => '9000000002', self::C => '9000000003'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function register(string $email, string $role = 'public')
    {
        Auth::logout();

        return $this->postJson(route('register.submit'), [
            'name' => 'Test Person', 'email' => $email, 'mobile' => self::MOBILES[$email], 'password' => 'A-long-test-password1', 'password_confirmation' => 'A-long-test-password1', 'role' => $role, 'accept_terms' => '1',
        ]);
    }

    private function messages(): Collection
    {
        return collect(app('mailer')->getSymfonyTransport()->messages());
    }

    /** The real envelope recipients of every message, in order. */
    private function recipients(): array
    {
        return $this->messages()->flatMap(fn ($sent) => array_map(fn ($to) => $to->getAddress(), $sent->getEnvelope()->getRecipients()))->all();
    }

    public function test_each_person_gets_their_own_message_and_their_own_code(): void
    {
        $this->register(self::A)->assertOk();
        $this->register(self::B)->assertOk();
        $this->register(self::C, 'notary')->assertOk();

        $this->assertSame([self::A, self::B, self::C], $this->recipients());

        $users = array_map(fn ($email) => User::where('email', $email)->sole(), [self::A, self::B, self::C]);
        $codes = [];
        foreach ($users as $index => $user) {
            $body = quoted_printable_decode($this->messages()[$index]->toString());
            $this->assertSame(1, preg_match('~verification code is (\d{6})~', $body, $m), 'a code in the email');
            $this->assertStringNotContainsString('/email/verify/', $body, 'no link');
            $codes[$index] = $m[1];
        }

        // A person's code works for that person only.
        $otp = app(OtpService::class);
        foreach ($users as $index => $user) {
            foreach ($codes as $other => $code) {
                if ($other !== $index && $code !== $codes[$index]) {
                    $this->assertNotSame(OtpService::OK, $otp->verifyEmailCode($user, $code));
                }
            }
            $this->assertSame(OtpService::OK, $otp->verifyEmailCode($user, $codes[$index]));
        }
    }

    public function test_resend_goes_to_the_account_that_asked(): void
    {
        $this->register(self::A);
        $this->register(self::B);
        $b = User::where('email', self::B)->sole();
        MessageDelivery::query()->delete();
        // A new code waits a minute after the one sent at registration.
        $this->travel(61)->seconds();

        $this->actingAs($b)->post(route('verification.send'))->assertSessionHas('status');

        $this->assertSame([self::A, self::B, self::B], $this->recipients());
    }

    public function test_a_password_reset_goes_only_to_the_address_entered(): void
    {
        foreach ([self::A, self::B, self::C] as $email) {
            User::factory()->create(['email' => $email, 'mobile' => self::MOBILES[$email], 'role' => $email === self::C ? 'notary' : 'public']);
        }

        $this->post(route('password.email'), ['identifier' => self::B]);
        $this->post(route('password.email'), ['identifier' => self::C]);

        $this->assertSame([self::B, self::C], $this->recipients());
    }

    public function test_the_code_of_a_cannot_be_used_by_b(): void
    {
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
        $code = null;
        Http::fake(function ($request) use (&$code) {
            $code = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });
        $make = fn ($email) => User::factory()->create(['email' => $email, 'mobile' => self::MOBILES[$email], 'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => null]);
        $a = $make(self::A);
        $b = $make(self::B);

        $this->actingAs($a)->post(route('mobile.verification.send'));
        $this->actingAs($b)->post(route('mobile.verification.verify'), ['otp' => $code])->assertSessionHasErrors('otp');

        $this->assertNull($b->fresh()->mobile_verified_at);
        $this->assertSame(OtpService::OK, app(OtpService::class)->verify($a->mobile, (string) $code));
    }

    /** @return array<string, array{string}> */
    public static function invalidAddresses(): array
    {
        return [
            'no domain' => ['person@'],
            'no name' => ['@example.test'],
            'no at sign' => ['person.example.test'],
            'a space' => ['per son@example.test'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_an_invalid_address_makes_no_account_and_no_message(string $email): void
    {
        $this->postJson(route('register.submit'), [
            'name' => 'Test Person', 'email' => $email, 'mobile' => '9000000009', 'password' => 'A-long-test-password1', 'password_confirmation' => 'A-long-test-password1', 'role' => 'public', 'accept_terms' => '1',
        ])->assertStatus(422);

        $this->assertSame(0, User::where('mobile', '9000000009')->count());
        $this->assertSame([], $this->recipients());
    }

    /** A queued mail for each address. The verification email is sent at once, so these use a security notice. */
    private function queueNotices(string ...$emails): void
    {
        foreach ($emails as $email) {
            User::factory()->create(['email' => $email, 'mobile' => self::MOBILES[$email], 'email_verified_at' => now()])
                ->notify(new SecurityAlertNotification('password_changed'));
        }
    }

    public function test_an_smtp_failure_at_registration_keeps_the_account_and_is_reported_as_failed(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->register(self::A)->assertOk()->assertJson(['success' => true])
            ->assertJsonPath('message', \App\Http\Controllers\Auth\RegisterController::CODE_NOT_SENT);

        // Sent at once, not queued: there is no job, and the record says failed, not "sent".
        $this->assertSame(1, User::where('email', self::A)->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $delivery = MessageDelivery::where('channel', 'mail')->sole();
        $this->assertSame(MessageDelivery::FAILED, $delivery->status);
        $this->assertSame('TransportException', $delivery->error);
        $this->assertStringNotContainsString(self::A, json_encode($delivery->toArray()));
    }

    public function test_an_smtp_failure_keeps_a_queued_job_for_a_retry(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->queueNotices(self::A);
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['--once' => true, '--tries' => 3]);

        $this->assertSame(1, User::where('email', self::A)->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'The job waits for its retry.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $delivery = MessageDelivery::where('channel', 'mail')->sole();
        $this->assertSame(MessageDelivery::QUEUED, $delivery->status);
        $this->assertStringContainsString('TransportException', (string) $delivery->error);
        $this->assertStringNotContainsString(self::A, json_encode($delivery->toArray()));
    }

    public function test_queued_jobs_keep_their_own_recipient(): void
    {
        config(['queue.default' => 'database']);

        $this->queueNotices(self::A, self::B, self::C);
        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertSame([], $this->recipients());

        // One at a time. A worker loop run here would share this long test process, count what
        // earlier tests left in memory against its own limit, and stop before the work is done.
        foreach (range(1, 3) as $job) {
            Artisan::call('queue:work', ['--once' => true]);
        }

        $this->assertSame([self::A, self::B, self::C], $this->recipients());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_worker_that_reaches_its_memory_limit_loses_no_mail_and_sends_none_twice(): void
    {
        config(['queue.default' => 'database']);

        $this->queueNotices(self::A, self::B, self::C);

        // The real limit, deliberately reached: the worker stops after the job in hand.
        $this->assertSame(Worker::EXIT_MEMORY_LIMIT, Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 1]));

        $this->assertSame([self::A], $this->recipients());
        $this->assertSame(2, DB::table('jobs')->count(), 'The rest wait for the next worker.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        foreach (range(1, 2) as $job) {
            Artisan::call('queue:work', ['--once' => true]);
        }

        $this->assertSame([self::A, self::B, self::C], $this->recipients());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_the_application_holds_no_override_or_personal_address(): void
    {
        $this->assertNull(config('mail.to'));

        $files = collect(File::allFiles(app_path()))->merge(File::allFiles(config_path()))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php');

        // No exception: the permanent Admin's address comes only from PROTECTED_ACCOUNTS in .env,
        // so no source or config file may name a real mailbox.
        foreach ($files as $file) {
            $source = $file->getContents();
            $this->assertDoesNotMatchRegularExpression('/alwaysTo\s*\(/', $source, $file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/[a-z0-9._%+-]+@gmail\.com/i', $source, $file->getPathname());
        }

        // The protected-account list is an access-protection list, never a mail recipient. It is read
        // by the protection check (User::isProtectedAccount) and counted, never printed or mailed, by
        // enotary:deploy-check.
        $readers = collect(File::allFiles(app_path()))
            ->filter(fn (SplFileInfo $file) => str_contains($file->getContents(), 'accounts.protected'))
            ->map(fn (SplFileInfo $file) => str_replace('\\', '/', $file->getRelativePathname()))
            ->sort()->values()->all();
        $this->assertSame(['Console/Commands/DeployCheck.php', 'Models/User.php'], $readers, 'Only the protection check and deploy-check may read the protected-account list.');
    }

    public function test_the_allowlist_stops_a_message_and_never_redirects_it(): void
    {
        config(['services.outbound.mail' => self::A]);

        $this->register(self::A)->assertOk()->assertJsonFragment(['message' => \App\Http\Controllers\Auth\RegisterController::CODE_SENT]);
        $this->register(self::B)->assertOk()->assertJsonFragment(['message' => \App\Http\Controllers\Auth\RegisterController::CODE_NOT_SENT]);

        $this->assertSame([self::A], $this->recipients());
        $this->assertSame(MessageDelivery::BLOCKED, MessageDelivery::where('recipient', 'ci***@example.test')->latest('id')->first()->status);
    }

    public function test_a_blocked_person_is_told_the_truth_on_the_page(): void
    {
        config(['services.outbound.mail' => self::A]);
        $this->register(self::B);

        $this->actingAs(User::where('email', self::B)->sole())->get(route('verification.notice'))->assertOk()
            ->assertSee("We couldn't send your verification code. Please try again later.")
            ->assertDontSee('has been sent')
            ->assertDontSee('test copy');
    }

    public function test_an_approved_person_still_sees_the_normal_page(): void
    {
        config(['services.outbound.mail' => self::A]);
        $this->register(self::A);

        $this->actingAs(User::where('email', self::A)->sole())->get(route('verification.notice'))->assertOk()
            ->assertSee('A verification code has been sent to your registered email address.')
            ->assertSee('Send a new code')
            ->assertDontSee("We couldn't send");
    }
}
