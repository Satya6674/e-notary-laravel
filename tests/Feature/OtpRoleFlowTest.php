<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every provider answer here is a recorded MSG91 body, faked. Nothing reaches MSG91. */
class OtpRoleFlowTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9876543210';

    private ?string $sentCode = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
    }

    private function user(string $role, array $values = []): User
    {
        return User::factory()->create($values + [
            'role' => $role, 'mobile' => self::MOBILE, 'email_verified_at' => now(), 'mobile_verified_at' => null,
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function acceptingProvider(): void
    {
        Http::fake(function ($request) {
            $this->sentCode = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });
    }

    #[DataProvider('roles')]
    public function test_each_role_verifies_its_own_number_with_the_real_code(string $role): void
    {
        $this->acceptingProvider();
        $user = $this->user($role);

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');
        $this->assertNull($user->fresh()->mobile_verified_at);

        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $this->sentCode]);

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->assertSame(1, AuditLog::where('action', 'auth.mobile_verified')->where('user_id', $user->id)->count());
        $this->assertNotNull(MessageDelivery::where('channel', 'sms')->where('user_id', $user->id)->value('confirmed_at'));
    }

    public static function roles(): array
    {
        return ['Citizen' => ['public'], 'Approved Notary' => ['notary']];
    }

    public function test_an_admin_has_no_otp_step_and_no_paid_message_can_be_triggered(): void
    {
        $this->acceptingProvider();
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('verification.mobile.notice'))->assertForbidden();
        $this->actingAs($admin)->post(route('mobile.verification.send'))->assertForbidden();
        $this->actingAs($admin)->post(route('mobile.verification.verify'), ['otp' => '123456'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($admin->fresh()->mobile_verified_at);
    }

    public function test_a_notary_awaiting_approval_cannot_spend_a_paid_message(): void
    {
        $this->acceptingProvider();
        $notary = $this->user('notary', ['notary_approved_at' => null]);

        $this->actingAs($notary)->get(route('verification.mobile.notice'))->assertRedirect();
        $this->actingAs($notary)->post(route('mobile.verification.send'))->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('otp_verifications')->count());
    }

    public function test_a_notary_cannot_change_a_number_through_the_citizen_step(): void
    {
        $notary = $this->user('notary');

        $this->actingAs($notary)->post(route('mobile.verification.number'), ['mobile' => '9123456789', 'password' => 'password'])->assertForbidden();

        $this->assertSame(self::MOBILE, $notary->fresh()->mobile);
    }

    public function test_the_notary_profile_offers_the_step_only_when_it_applies(): void
    {
        $link = route('verification.mobile.notice');

        $this->actingAs($this->user('notary'))->get(route('notary.profile'))->assertOk()->assertSee($link);

        $verified = $this->user('notary', ['mobile' => '9123456789', 'mobile_verified_at' => now()]);
        $this->actingAs($verified)->get(route('notary.profile'))->assertOk()->assertDontSee($link);
    }

    #[DataProvider('roles')]
    public function test_a_wrong_code_for_each_role_verifies_nothing_and_is_audited(string $role): void
    {
        $this->acceptingProvider();
        $user = $this->user($role);
        $this->actingAs($user)->post(route('mobile.verification.send'));

        $wrong = $this->sentCode === '000000' ? '000001' : '000000';
        $this->actingAs($user)->post(route('mobile.verification.verify'), ['otp' => $wrong]);

        $this->assertNull($user->fresh()->mobile_verified_at);

        $entry = AuditLog::where('action', 'auth.otp_failed')->where('user_id', $user->id)->sole();
        $this->assertStringNotContainsString($wrong, json_encode($entry->meta));
        $this->assertStringNotContainsString(self::MOBILE, json_encode($entry->meta));
    }

    /** @return array<string, array{array<string, string>, string}> Bodies MSG91 really returns. */
    public static function refusals(): array
    {
        return [
            'IP not whitelisted' => [['message' => 'IP is not whitelisted', 'type' => 'error', 'code' => '418'], 'provider:IP is not whitelisted'],
            'No permission'      => [['message' => 'Authkey has no permission to send an sms.', 'type' => 'error', 'code' => '204'], 'provider:Authkey has no permission to send an sms.'],
            'Template missing'   => [['message' => 'Template Id Missing', 'type' => 'error', 'code' => '211'], 'provider:Template Id Missing'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_send_is_reported_and_verifies_nothing(array $body, string $reason): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $user = $this->user('public');

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('error');

        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::where('channel', 'sms')->value('status'));
        $this->assertSame($reason, MessageDelivery::where('channel', 'sms')->value('error'));
        $this->assertNull($user->fresh()->mobile_verified_at);
        $this->assertSame(OtpService::EXPIRED, app(OtpService::class)->verify(self::MOBILE, '123456'));
    }

    public function test_provider_acceptance_alone_never_verifies_a_number(): void
    {
        $this->acceptingProvider();
        $user = $this->user('public');

        $this->actingAs($user)->post(route('mobile.verification.send'));

        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('channel', 'sms')->value('status'));
        $this->assertNull(MessageDelivery::where('channel', 'sms')->value('confirmed_at'));
        $this->assertNull($user->fresh()->mobile_verified_at);
    }

    public function test_the_code_is_stored_only_as_a_hash_and_never_logged(): void
    {
        $this->acceptingProvider();
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });

        app(OtpService::class)->issue(self::MOBILE, '127.0.0.1');

        $stored = DB::table('otp_verifications')->value('otp');
        $this->assertNotSame($this->sentCode, $stored);
        // Module 6: the flow tag, then the bcrypt hash; the code never appears.
        $this->assertStringStartsWith('mobile_verification|$2y$', $stored);
        $this->assertStringNotContainsString($this->sentCode, $stored);
        $this->assertStringNotContainsString($this->sentCode, implode("\n", $logged));
        $this->assertStringNotContainsString(self::MOBILE, implode("\n", $logged));
        $this->assertStringNotContainsString('test-key', implode("\n", $logged));
    }

    public function test_a_code_expires_at_the_time_the_template_promises(): void
    {
        $this->assertSame(10, OtpService::ttl());

        $this->acceptingProvider();
        app(OtpService::class)->issue(self::MOBILE, '127.0.0.1');

        $this->travel(9)->minutes();
        $this->assertNotSame(OtpService::EXPIRED, app(OtpService::class)->verify(self::MOBILE, '000000' === $this->sentCode ? '000001' : '000000'));

        $this->travel(2)->minutes();
        $this->assertSame(OtpService::EXPIRED, app(OtpService::class)->verify(self::MOBILE, $this->sentCode));
    }

    public function test_a_wrong_recovery_code_is_audited_against_the_account_only(): void
    {
        $this->acceptingProvider();
        $user = $this->user('notary', ['mobile_verified_at' => now()]);

        $this->post(route('password.email'), ['identifier' => self::MOBILE]);
        $wrong = $this->sentCode === '000000' ? '000001' : '000000';
        $this->post(route('password.verify.otp'), ['otp' => $wrong]);

        $this->assertSame(1, AuditLog::where('action', 'auth.otp_failed')->where('user_id', $user->id)->count());
    }

    public function test_a_notary_recovers_through_the_code_emailed_to_its_address(): void
    {
        $notary = $this->user('notary');

        $this->post(route('password.email'), ['identifier' => self::MOBILE]);
        $code = Notification::sent($notary, \App\Notifications\PasswordResetCodeNotification::class)->last()->code;
        $this->post(route('password.verify.otp'), ['otp' => $code])->assertRedirect(route('password.reset.form'));

        Http::assertNothingSent();
    }

    public function test_an_admin_cannot_recover_through_a_mobile_number_and_nothing_goes_out(): void
    {
        $admin = $this->user('admin', ['mobile_verified_at' => now()]);

        $this->post(route('password.email'), ['identifier' => self::MOBILE])->assertSessionHas('status');

        Http::assertNothingSent();
        Notification::assertNotSentTo($admin, \App\Notifications\PasswordResetCodeNotification::class);
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset_refused')->where('user_id', $admin->id)->count());
    }

    #[DataProvider('endpoints')]
    public function test_only_a_local_address_can_replace_msg91(string $override, bool $production, string $expected): void
    {
        config(['services.msg91.endpoint' => $override]);
        $this->app['env'] = $production ? 'production' : 'testing';

        $this->assertSame($expected, SmsService::endpoint());
    }

    public static function endpoints(): array
    {
        return [
            'none'             => ['', false, SmsService::ENDPOINT],
            'local'            => ['http://127.0.0.1:8003/otp', false, 'http://127.0.0.1:8003/otp'],
            'another host'     => ['http://attacker.example/otp', false, SmsService::ENDPOINT],
            'look-alike host'  => ['http://127.0.0.1.attacker.example/otp', false, SmsService::ENDPOINT],
            'local, in production' => ['http://127.0.0.1:8003/otp', true, SmsService::ENDPOINT],
        ];
    }

    public function test_the_site_names_its_sms_mode(): void
    {
        $this->assertSame('live', Platform::smsMode());

        config(['services.msg91.endpoint' => 'http://127.0.0.1:8003/otp']);
        $this->assertSame('mock', Platform::smsMode());

        config(['services.msg91.auth_key' => null]);
        $this->assertSame('off', Platform::smsMode());
    }

    public function test_the_local_gmail_banner_never_says_sms_is_off_while_it_is_on(): void
    {
        config(['mail.mode' => 'local_gmail', 'mail.default' => 'smtp', 'services.outbound.mail' => 'a@example.test']);

        // An Admin-only diagnostic: a visitor never sees it.
        $this->get('/login')->assertDontSee('Local Gmail:')->assertDontSee('Paid SMS is on');

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'email' => 'banner.admin@example.test']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Paid SMS is on, for one approved number only.')->assertDontSee('SMS is off.');

        config(['services.msg91.auth_key' => null]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('SMS is off.');
    }
}
