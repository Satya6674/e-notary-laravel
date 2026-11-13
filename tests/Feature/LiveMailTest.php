<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Mail\TestMail;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\VerifyEmailNotification;
use App\Providers\AppServiceProvider;
use App\Services\OtpService;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use ReflectionMethod;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Live Mail: real delivery of the registration verification email, and of nothing else.
 *
 * Nothing here can reach a real server. The "capture" transport keeps messages in memory, the
 * failing one throws, and the SMTP mailer is pointed at a closed local port with dummy settings,
 * so even a broken rule could not connect to Gmail. Live Mail treats "capture" as a real transport
 * (only array and log are not), so every Live Mail rule is exercised.
 */
class LiveMailTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED = 'tester@example.test';

    private const OTHER = 'outsider@example.test';

    private const APP_URL = 'http://127.0.0.1:8000';

    private ArrayTransport $captured;

    private int $mobile = 9000000001;

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Symfony keeps trusted-host patterns in a static. A test that ran in production mode
        // earlier in this process may have left one, which would answer a foreign host with 400.
        \Symfony\Component\HttpFoundation\Request::setTrustedHosts([]);

        $this->captured = new ArrayTransport;
        Mail::extend('capture', fn () => $this->captured);
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Connection could not be established with host "smtp.example.test:587"');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        config([
            'mail.live_enabled' => true,
            'mail.default' => 'capture',
            'mail.mailers.capture' => ['transport' => 'capture'],
            'mail.mailers.failing' => ['transport' => 'failing'],
            // Never Gmail: a closed local port and dummy values, whatever .env holds.
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'username' => 'dummy', 'password' => 'Dummy-password1', 'timeout' => 2],
            'mail.from.address' => 'sender@example.test',
            'services.outbound.mail' => self::ALLOWED,
            'app.url' => self::APP_URL,
        ]);
        app('mail.manager')->forgetMailers();

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    private function register(string $email, array $server = [])
    {
        Auth::logout();

        return $this->withServerVariables($server)->postJson(route('register.submit'), [
            'name' => 'Live Mail Tester', 'email' => $email, 'mobile' => (string) $this->mobile++,
            'password' => 'A-long-test-password1', 'password_confirmation' => 'A-long-test-password1', 'role' => 'public', 'accept_terms' => '1',
        ]);
    }

    private function messages(): array
    {
        return array_values(iterator_to_array($this->captured->messages()));
    }

    private function delivery(string $email): ?MessageDelivery
    {
        return MessageDelivery::where('user_id', User::where('email', $email)->value('id'))->latest('id')->first();
    }

    /**
     * A signed verification link, built on APP_URL the way the earlier emails built theirs. The email
     * now carries the code only (approved 2026-11-08); the signed route stays for links sent before.
     */
    private function link(?User $user = null): string
    {
        $user ??= User::where('email', self::ALLOWED)->firstOrFail();

        return \App\Support\AppUrl::using(fn () => \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]
        ));
    }

    private function code(): string
    {
        $text = (string) $this->messages()[0]->getOriginalMessage()->getTextBody().' '.$this->messages()[0]->getOriginalMessage()->getHtmlBody();
        $this->assertSame(1, preg_match('~verification code is (\d{6})~', $text, $match));

        return $match[1];
    }

    private function newestEmailCodeIsCancelled(string $email): bool
    {
        $row = DB::table('otp_verifications')->where('mobile', OtpService::emailKey(User::where('email', $email)->firstOrFail()))->orderByDesc('id')->first();

        return $row !== null && now()->greaterThanOrEqualTo($row->expires_at);
    }

    public function test_a_new_registration_sends_one_verification_email_to_the_allowlisted_address(): void
    {
        $this->assertSame('live_test', Platform::mailMode());

        $this->register(self::ALLOWED)->assertOk()->assertJson(['success' => true])
            ->assertJsonPath('message', RegisterController::CODE_SENT);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame([self::ALLOWED], array_map(fn ($a) => $a->getAddress(), $messages[0]->getEnvelope()->getRecipients()));

        $delivery = $this->delivery(self::ALLOWED);
        $this->assertSame(MessageDelivery::ACCEPTED, $delivery->status);
        $this->assertSame('verify_email', $delivery->purpose);
        $this->assertSame('te***@example.test', $delivery->recipient, 'the delivery record keeps a masked address only');

        // Sent at once: no job ever held the code.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('off', Platform::smsMode(), 'SMS stays off');
    }

    public function test_the_email_carries_no_link_and_links_elsewhere_are_built_on_app_url_whatever_the_host(): void
    {
        $this->register(self::ALLOWED, ['HTTP_HOST' => 'attacker.example'])->assertOk();

        // The code-only email has no link at all, so a forged Host has nothing to shape.
        $body = html_entity_decode((string) $this->messages()[0]->getOriginalMessage()->getHtmlBody());
        $this->assertStringNotContainsString('/email/verify/', $body);
        $this->assertStringNotContainsString('attacker.example', $body);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->code());

        // A link built for any other email is rooted at APP_URL.
        $this->assertStringStartsWith(self::APP_URL.'/email/verify/', $this->link());

        // The generator is put back afterwards, so the rest of the request is unaffected.
        config(['app.url' => 'https://mail-root.test']);
        $this->assertSame('https://mail-root.test/probe', \App\Support\AppUrl::using(fn () => url('/probe')));
        $this->assertStringNotContainsString('mail-root.test', url('/probe'));
    }

    public function test_a_link_sent_before_the_code_only_email_still_verifies_the_right_account(): void
    {
        $this->register('bystander@example.test');
        config(['services.outbound.mail' => self::ALLOWED.',bystander@example.test']);
        $this->captured->flush();

        $this->register(self::ALLOWED)->assertOk();
        $user = User::where('email', self::ALLOWED)->firstOrFail();
        $link = $this->link($user);

        Auth::logout();
        $this->get($link)->assertRedirectContains('/login');

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('signed_link', $user->email_verification_method);
        $this->assertFalse(User::where('email', 'bystander@example.test')->firstOrFail()->hasVerifiedEmail(), 'no other account changes');
        $this->assertSame(1, DB::table('verification_events')->where('user_id', $user->id)->where('method', 'signed_link')->count());

        // Signed in, the same kind of link goes to the account's next step: the mobile page.
        $this->assertSame(route('verification.mobile.notice'), $user->landingUrl());
    }

    public function test_a_modified_or_expired_link_is_refused_and_verifies_nothing(): void
    {
        $this->register(self::ALLOWED)->assertOk();
        $user = User::where('email', self::ALLOWED)->firstOrFail();
        $link = $this->link($user);
        Auth::logout();

        // One character of the signature changed.
        $tampered = substr($link, 0, -1).(str_ends_with($link, '0') ? '1' : '0');
        $this->get($tampered)->assertForbidden();
        $this->get(preg_replace('~/email/verify/\d+/~', '/email/verify/'.($user->id + 1).'/', $link))->assertForbidden();
        $this->get(str_replace(self::APP_URL, 'http://attacker.example', $link))->assertForbidden();

        $this->travel(61)->minutes();
        $this->get($link)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_address_outside_the_allowlist_receives_nothing_and_its_code_is_cancelled(): void
    {
        $this->register(self::OTHER)->assertOk()
            ->assertJsonPath('message', RegisterController::CODE_NOT_SENT);

        $this->assertCount(0, $this->messages());
        $this->assertSame(MessageDelivery::BLOCKED, $this->delivery(self::OTHER)->status);
        $this->assertSame('recipient_not_allowlisted', $this->delivery(self::OTHER)->error);
        $this->assertTrue($this->newestEmailCodeIsCancelled(self::OTHER));
    }

    public function test_business_emails_reach_an_allowlisted_address_and_the_excluded_kinds_stay_blocked(): void
    {
        $user = User::factory()->create(['email' => self::ALLOWED, 'email_verified_at' => now()]);

        // Business notifications are live types now (system-wide Live Mail).
        $user->notify(new ResetPasswordNotification(Password::broker()->createToken($user)));
        $user->notify(new SecurityAlertNotification('password_changed'));
        $user->notify(new PortalNotification('Hello', 'A portal notice', route('home')));
        $this->assertCount(3, $this->messages());

        // Never live: a plain mailable (enotary:mail-test), the development bridge, the contact acknowledgement.
        Mail::to(self::ALLOWED)->send(new TestMail);
        $user->notify(new \App\Notifications\MobileOtpTestNotification('123456'));
        $this->assertCount(3, $this->messages());
        $this->assertSame(1, MessageDelivery::where(['user_id' => $user->id, 'status' => MessageDelivery::BLOCKED, 'error' => 'purpose_not_live'])->count());

        // The allowlist still applies to every kind.
        $other = User::factory()->create(['email' => self::OTHER, 'email_verified_at' => now()]);
        $other->notify(new SecurityAlertNotification('password_changed'));
        $this->assertCount(3, $this->messages());
    }

    public static function missingSettings(): array
    {
        return [
            'no recipient allowlist' => [['services.outbound.mail' => ''], 'recipient_allowlist_missing'],
            'no From address' => [['mail.from.address' => null], 'from_address_missing'],
            'APP_URL not a URL' => [['app.url' => 'not a url'], 'app_url_invalid'],
            'SMTP without a password' => [['mail.default' => 'smtp', 'mail.mailers.smtp.password' => ''], 'smtp_settings_missing'],
            'SMTP with an empty host' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => ''], 'smtp_settings_missing'],
            'SMTP with no host at all' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null], 'smtp_settings_missing'],
            'SMTP without a username' => [['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null], 'smtp_settings_missing'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingSettings')]
    public function test_a_missing_setting_sends_nothing_and_says_so(array $settings, string $problem): void
    {
        config($settings);
        app('mail.manager')->forgetMailers();

        $this->assertSame('misconfigured', Platform::mailMode());
        $this->assertSame($problem, Platform::liveMailProblem());
        if ($problem === 'recipient_allowlist_missing') {
            $this->assertFalse(\App\Services\DeliveryLog::allowed('mail', self::ALLOWED), 'an empty allowlist means nobody, never everybody');
        }

        $this->register(self::ALLOWED)->assertOk()->assertJsonPath('message', RegisterController::CODE_NOT_SENT);

        $this->assertCount(0, $this->messages());
        $delivery = $this->delivery(self::ALLOWED);
        $this->assertSame(MessageDelivery::FAILED, $delivery->status, 'stopped before the transport: never reported as sent');
        $this->assertSame('misconfigured:'.$problem, $delivery->error);

        $user = User::where('email', self::ALLOWED)->firstOrFail();
        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee(RegisterController::CODE_NOT_SENT)->assertDontSee('switched on but not set up');
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('error', EmailVerificationController::CODE_NOT_SENT);
    }

    public function test_production_requires_an_https_app_url(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame('https_required', Platform::liveMailProblem());

        config(['app.url' => 'https://enotary.example']);
        $this->assertNull(Platform::liveMailProblem());
    }

    public function test_an_smtp_failure_is_reported_truthfully_and_a_later_resend_succeeds(): void
    {
        config(['mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();

        $this->register(self::ALLOWED)->assertOk()
            ->assertJsonPath('message', RegisterController::CODE_NOT_SENT);

        $delivery = $this->delivery(self::ALLOWED);
        $this->assertSame(MessageDelivery::FAILED, $delivery->status);
        $this->assertSame('TransportException', $delivery->error, 'the class name only, never the server reply');
        $this->assertTrue($this->newestEmailCodeIsCancelled(self::ALLOWED), 'a code nobody received cannot be used');

        $user = User::where('email', self::ALLOWED)->firstOrFail();
        $warning = collect($this->logged)->firstWhere('message', 'Email could not be sent');
        $this->assertSame(['to' => 'te***@example.test', 'error' => 'TransportException'], $warning['context']);

        // A resend while the server is still down says so too.
        $this->travel(61)->seconds();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertSessionHas('error', EmailVerificationController::CODE_NOT_SENT);

        // Once it is back, a resend is accepted and only then reported as sent.
        config(['mail.default' => 'capture']);
        app('mail.manager')->forgetMailers();
        $this->travel(61)->seconds();
        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertSessionHas('status', EmailVerificationController::CODE_SENT);
        $this->assertCount(1, $this->messages());
        $this->assertSame(MessageDelivery::ACCEPTED, $this->delivery(self::ALLOWED)->status);

        $this->actingAs($user)->post(route('verification.code'), ['code' => $this->code()])->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_is_rate_limited(): void
    {
        $this->register(self::ALLOWED)->assertOk();
        $user = User::where('email', self::ALLOWED)->firstOrFail();
        $resend = fn () => $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));

        $resend()->assertSessionHas('error');
        $this->assertCount(1, $this->messages(), 'nothing more inside the minute');

        $this->travel(61)->seconds();
        $resend()->assertSessionHas('status', EmailVerificationController::CODE_SENT);
        $this->assertCount(2, $this->messages());
    }

    public function test_logs_never_hold_the_code_or_the_address(): void
    {
        $this->register(self::ALLOWED)->assertOk();
        $code = $this->code();

        $this->register(self::OTHER);
        config(['mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();
        $this->travel(61)->seconds();
        $this->actingAs(User::where('email', self::ALLOWED)->firstOrFail())->post(route('verification.send'));

        $this->assertNotEmpty($this->logged);
        $all = json_encode($this->logged);
        foreach ([$code, self::ALLOWED, self::OTHER, 'A-long-test-password1', 'Dummy-password1'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_safe_preview_stays_the_default_when_live_mail_is_off(): void
    {
        $enforce = new ReflectionMethod(AppServiceProvider::class, 'enforceSafePreviewDefault');

        // A real transport configured (as if MAIL_MAILER=smtp) but Live Mail off: replaced by the log.
        config(['mail.live_enabled' => false, 'mail.default' => 'smtp']);
        $enforce->invoke(new AppServiceProvider($this->app));
        app('mail.manager')->forgetMailers();

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('safe_preview', Platform::mailMode());

        $this->register(self::ALLOWED)->assertOk()
            ->assertJsonPath('message', RegisterController::CODE_NOT_SENT);
        $this->assertCount(0, $this->messages());
        $this->assertSame(MessageDelivery::SIMULATED, $this->delivery(self::ALLOWED)->status);

        // Local Gmail, started by its own script, is not overridden.
        config(['mail.live_enabled' => false, 'mail.default' => 'smtp', 'mail.mode' => 'local_gmail']);
        $enforce->invoke(new AppServiceProvider($this->app));
        $this->assertSame('smtp', config('mail.default'));
    }

    public static function permanentDatabases(): array
    {
        return ['enotary_local' => ['enotary_local'], 'enotary' => ['enotary'], 'any letter case' => ['ENOTARY_LOCAL']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permanentDatabases')]
    public function test_live_mail_refuses_to_send_from_a_permanent_database(string $permanent): void
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();
        $connection->setDatabaseName($permanent);

        try {
            $this->assertSame('misconfigured', Platform::mailMode());
            $this->assertSame('permanent_database', Platform::liveMailProblem());
            $this->assertSame('misconfigured:permanent_database', Platform::liveMailRefusal(VerifyEmailNotification::class));

            $this->register(self::ALLOWED)->assertOk()->assertJsonPath('message', RegisterController::CODE_NOT_SENT);
            $this->assertCount(0, $this->messages());
            $this->assertSame('misconfigured:permanent_database', $this->delivery(self::ALLOWED)->error);
        } finally {
            $connection->setDatabaseName($name);
        }

        // The same settings on a disposable database are fine.
        $this->assertNull(Platform::liveMailProblem());
    }

    public function test_mail_test_is_blocked_under_live_mail_and_says_so(): void
    {
        $this->artisan('enotary:mail-test', ['to' => self::ALLOWED, '--force' => true])
            ->expectsOutputToContain('BLOCKED: not sent (purpose_not_live)')
            ->doesntExpectOutputToContain('ACCEPTED')
            ->assertExitCode(1);

        $this->assertCount(0, $this->messages());
    }

    public function test_mail_test_reports_simulated_in_safe_preview(): void
    {
        config(['mail.live_enabled' => false, 'mail.default' => 'log']);
        app('mail.manager')->forgetMailers();

        $this->artisan('enotary:mail-test', ['to' => self::ALLOWED, '--force' => true])
            ->expectsOutputToContain('SIMULATED')
            ->doesntExpectOutputToContain('ACCEPTED')
            ->assertExitCode(2);
    }

    public function test_mail_test_reports_a_transport_failure_as_failed(): void
    {
        config(['mail.live_enabled' => false, 'mail.default' => 'failing']);
        app('mail.manager')->forgetMailers();

        $this->artisan('enotary:mail-test', ['to' => self::ALLOWED, '--force' => true])
            ->expectsOutputToContain('FAILED: Symfony\Component\Mailer\Exception\TransportException')
            ->doesntExpectOutputToContain('ACCEPTED')
            ->assertExitCode(1);
    }

    public function test_mail_test_reports_the_allowlist_as_blocked_and_refuses_a_failover_mailer(): void
    {
        config(['mail.live_enabled' => false]);

        $this->artisan('enotary:mail-test', ['to' => self::OTHER, '--force' => true])
            ->expectsOutputToContain('BLOCKED: not sent (recipient_not_allowlisted)')
            ->assertExitCode(1);

        config(['mail.default' => 'failover']);
        $this->artisan('enotary:mail-test', ['to' => self::ALLOWED, '--force' => true])
            ->expectsOutputToContain('NOT ATTEMPTED')
            ->assertExitCode(2);

        $this->assertCount(0, $this->messages());
    }

    public function test_mail_test_reports_accepted_only_when_the_transport_took_it(): void
    {
        config(['mail.live_enabled' => false]);

        $this->artisan('enotary:mail-test', ['to' => self::ALLOWED, '--force' => true])
            ->expectsOutputToContain('ACCEPTED by the mail transport (capture)')
            ->assertExitCode(0);

        $this->assertCount(1, $this->messages());
    }

    public function test_the_unflagged_array_mailer_of_the_test_suite_is_not_affected(): void
    {
        config(['mail.live_enabled' => false, 'mail.default' => 'array']);

        $this->assertFalse(Platform::liveMailApplies());
        $this->assertNull(Platform::liveMailRefusal(SecurityAlertNotification::class));
        $this->assertNull(Platform::liveMailRefusal(VerifyEmailNotification::class));
    }
}
