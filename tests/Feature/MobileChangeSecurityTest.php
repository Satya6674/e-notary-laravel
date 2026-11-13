<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Notifications\SecurityAlertNotification;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * BR-002. A registered number changes only with the password and proof of the new number.
 *
 * Evidence level: MOCKED SMS. SmsService never reaches MSG91, so these prove the rules,
 * not delivery. Real SMS remains blocked (BR-004).
 */
class MobileChangeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '9500000001';

    private const NEW = '9500000002';

    private ?string $code = null;

    private ?string $sentTo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    private function user(array $extra = []): User
    {
        static $i = 100;
        $i++;

        // forceFill: pending_mobile is not mass assignable, and create() would drop it silently.
        $user = new User(['name' => "Person {$i}", 'email' => "m{$i}@example.test", 'password' => Hash::make('right-password')]);

        $user->forceFill(array_merge([
            'mobile' => self::OLD, 'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ], $extra))->save();

        return $user->fresh();
    }

    private function mockSms(bool $accepted = true): void
    {
        $this->mock(SmsService::class, function ($mock) use ($accepted) {
            $mock->shouldReceive('send')->andReturnUsing(function ($mobile, $code) use ($accepted) {
                $this->sentTo = $mobile;
                $this->code = $code;

                return $accepted;
            });
            $mock->lastRequestId = 'test-request';
            $mock->lastError = $accepted ? null : 'refused';
        });
    }

    private function noSmsAllowed(): void
    {
        $this->mock(SmsService::class, fn ($mock) => $mock->shouldNotReceive('send'));
    }

    private function start(User $user, array $data = [])
    {
        return $this->actingAs($user)->post(route('user.mobile.change'), $data + ['mobile' => self::NEW, 'current_password' => 'right-password']);
    }

    private function confirm(User $user, ?string $otp = null)
    {
        return $this->actingAs($user->fresh())->post(route('user.mobile.change.verify'), ['otp' => $otp ?? $this->code]);
    }

    // ---- Reauthentication

    public function test_the_profile_form_can_no_longer_change_the_number(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put('/user/profile', ['name' => $user->name, 'email' => $user->email, 'mobile' => self::NEW])
            ->assertSessionHasErrors('mobile');

        $this->assertSame(self::OLD, $user->fresh()->mobile);
    }

    public function test_the_profile_form_still_saves_other_fields_without_a_mobile_field(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put('/user/profile', ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame(self::OLD, $user->fresh()->mobile);
    }

    public function test_a_stolen_session_without_the_password_gets_nowhere(): void
    {
        $user = $this->user();
        $this->noSmsAllowed();

        $this->start($user, ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->start($user, ['current_password' => ''])->assertSessionHasErrors('current_password');

        $this->assertNull($user->fresh()->pending_mobile);
        $this->assertSame(1, AuditLog::where('action', 'user.mobile_change_denied')->count());
        Notification::assertNotSentTo($user, SecurityAlertNotification::class);
    }

    public function test_the_step_page_also_needs_the_password(): void
    {
        $user = $this->user(['mobile_verified_at' => null]);

        $this->actingAs($user)->post('/mobile/number', ['mobile' => self::NEW])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->post('/mobile/number', ['mobile' => self::NEW, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertSame(self::OLD, $user->fresh()->mobile);

        $this->actingAs($user)->post('/mobile/number', ['mobile' => self::NEW, 'current_password' => 'right-password'])->assertSessionHas('status');
        $this->assertSame(self::NEW, $user->fresh()->mobile);
        $this->assertNull($user->fresh()->mobile_verified_at, 'a typed number is not a verified number');
        Notification::assertSentTo($user, SecurityAlertNotification::class, fn ($n) => $n->event === 'mobile_changed');
    }

    // ---- Proving the new number

    public function test_the_old_number_stays_in_force_until_the_new_one_is_proven(): void
    {
        $user = $this->user();
        $this->mockSms();

        $this->start($user)->assertRedirect(route('user.profile'));

        $held = $user->fresh();
        $this->assertSame(self::OLD, $held->mobile);
        $this->assertTrue($held->hasVerifiedMobile());
        $this->assertSame(self::NEW, $held->pending_mobile);
        $this->assertSame(self::NEW, $this->sentTo, 'the code must go to the new number');
        $this->actingAs($held)->get('/dashboard')->assertOk();
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->confirm($user, $this->code === '000000' ? '000001' : '000000')->assertSessionHasErrors('otp');

        $this->assertSame(self::OLD, $user->fresh()->mobile);
        $this->assertSame(self::NEW, $user->fresh()->pending_mobile);
    }

    public function test_the_right_code_activates_the_new_number_as_verified(): void
    {
        $user = $this->user(['mobile_verified_at' => now()->subYear()]);
        $this->mockSms();
        $this->start($user);

        $this->confirm($user)->assertRedirect(route('user.profile'))->assertSessionHas('status');

        $fresh = $user->fresh();
        $this->assertSame(self::NEW, $fresh->mobile);
        $this->assertNull($fresh->pending_mobile);
        $this->assertNull($fresh->pending_mobile_requested_at);
        $this->assertTrue($fresh->mobile_verified_at->isToday());
        $this->assertSame(0, DB::table('otp_verifications')->count());
    }

    public function test_the_number_comes_from_the_server_not_the_request(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->actingAs($user->fresh())->post(route('user.mobile.change.verify'), ['otp' => $this->code, 'mobile' => '9500000099', 'mobile_verified_at' => null]);

        $this->assertSame(self::NEW, $user->fresh()->mobile);
    }

    public function test_one_account_cannot_finish_another_accounts_change(): void
    {
        $victim = $this->user();
        $other = $this->user(['mobile' => '9500000050']);
        $this->mockSms();
        $this->start($victim);

        $this->confirm($other)->assertSessionHas('error');

        $this->assertSame('9500000050', $other->fresh()->mobile);
        $this->assertSame(self::OLD, $victim->fresh()->mobile);
    }

    public function test_a_stale_request_expires(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->travel(User::PENDING_MOBILE_MINUTES + 1)->minutes();

        $this->confirm($user)->assertSessionHas('error');

        $this->assertSame(self::OLD, $user->fresh()->mobile);
    }

    public function test_a_refused_sms_leaves_no_pending_change(): void
    {
        $user = $this->user();
        $this->mockSms(accepted: false);

        $this->start($user)->assertSessionHas('error');

        $this->assertNull($user->fresh()->pending_mobile);
        Notification::assertNotSentTo($user, SecurityAlertNotification::class);
    }

    public function test_a_number_on_another_account_is_refused(): void
    {
        $this->user(['mobile' => self::NEW]);
        $user = $this->user();
        $this->noSmsAllowed();

        $this->start($user)->assertSessionHasErrors('mobile');

        $this->assertNull($user->fresh()->pending_mobile);
    }

    public function test_a_number_taken_while_waiting_is_refused_at_activation(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->user(['mobile' => self::NEW]);

        $this->confirm($user)->assertSessionHas('error');

        $this->assertSame(self::OLD, $user->fresh()->mobile);
        $this->assertNull($user->fresh()->pending_mobile);
    }

    public function test_the_same_number_is_refused(): void
    {
        $user = $this->user();
        $this->noSmsAllowed();

        $this->start($user, ['mobile' => self::OLD])->assertSessionHasErrors('mobile');
    }

    public function test_cancelling_removes_the_held_number_and_its_code(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->actingAs($user->fresh())->delete(route('user.mobile.change.cancel'))->assertRedirect(route('user.profile'));

        $this->assertNull($user->fresh()->pending_mobile);
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', self::NEW)->count());
        $this->assertSame(1, AuditLog::where('action', 'user.mobile_change_cancelled')->count());
    }

    // ---- Telling the owner

    public function test_the_owner_is_told_at_both_ends(): void
    {
        $user = $this->user();
        $this->mockSms();

        $this->start($user);
        Notification::assertSentTo($user, SecurityAlertNotification::class, fn ($n) => $n->event === 'mobile_change_requested');

        $this->confirm($user);
        Notification::assertSentTo($user, SecurityAlertNotification::class, fn ($n) => $n->event === 'mobile_changed');
        Notification::assertSentTo($user, PortalNotification::class, fn ($n) => $n->title === 'Mobile number changed');
    }

    public function test_the_alert_goes_to_the_email_on_the_account_and_carries_no_number(): void
    {
        $alert = new SecurityAlertNotification('mobile_changed');

        $this->assertSame(['mail'], $alert->via($this->user()));
        $this->assertArrayHasKey('mobile_change_requested', SecurityAlertNotification::EVENTS);
        $this->assertDoesNotMatchRegularExpression('/\d{4}/', implode(' ', SecurityAlertNotification::EVENTS));
    }

    public function test_an_admin_edit_tells_the_owner_and_drops_any_held_change(): void
    {
        $target = $this->user(['pending_mobile' => self::NEW, 'pending_mobile_requested_at' => now()]);
        $admin = $this->user(['role' => 'admin', 'mobile' => '9500000060']);

        $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'mobile' => '9500000070', 'role' => 'public',
        ])->assertRedirect();

        $this->assertNull($target->fresh()->pending_mobile);
        $this->assertNull($target->fresh()->mobile_verified_at);
        Notification::assertSentTo($target, SecurityAlertNotification::class, fn ($n) => $n->event === 'mobile_changed');
    }

    // ---- Audit and limits

    public function test_every_step_is_audited_without_either_number(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user, ['current_password' => 'wrong']);
        $this->start($user);
        $this->confirm($user);

        $entries = AuditLog::where('action', 'like', 'user.mobile_%')->pluck('action')->all();

        $this->assertSame(['user.mobile_change_denied', 'user.mobile_change_requested', 'user.mobile_changed'], $entries);

        $text = AuditLog::where('action', 'like', 'user.mobile_%')->get()->toJson();
        $this->assertStringNotContainsString(self::OLD, $text);
        $this->assertStringNotContainsString(self::NEW, $text);
        $this->assertStringNotContainsString('right-password', $text);
    }

    public function test_password_guessing_is_rate_limited(): void
    {
        $user = $this->user();
        $this->noSmsAllowed();

        for ($i = 0; $i < 5; $i++) {
            $this->start($user, ['current_password' => "guess-{$i}"])->assertSessionHasErrors('current_password');
        }

        $this->start($user, ['current_password' => 'right-password'])->assertStatus(429);
        $this->assertNull($user->fresh()->pending_mobile);
    }

    public function test_changes_are_capped_per_day(): void
    {
        $user = $this->user();
        $this->mockSms();

        RateLimiter::hit('mobile-change:'.$user->id, 86400);
        RateLimiter::hit('mobile-change:'.$user->id, 86400);
        RateLimiter::hit('mobile-change:'.$user->id, 86400);

        $this->start($user)->assertSessionHas('error');

        $this->assertNull($user->fresh()->pending_mobile);
        $this->assertNull($this->code);
    }

    public function test_resending_respects_the_cooldown(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->actingAs($user->fresh())->from('/user/profile')->post(route('user.mobile.change.resend'))->assertSessionHas('error');

        $this->travel(61)->seconds();
        $this->actingAs($user->fresh())->from('/user/profile')->post(route('user.mobile.change.resend'))->assertSessionHas('status');
    }

    // ---- Who may reach this

    public function test_guests_and_staff_cannot_reach_it(): void
    {
        $this->post(route('user.mobile.change'), ['mobile' => self::NEW])->assertRedirect(route('login'));

        foreach (['admin', 'notary'] as $role) {
            $staff = $this->user(['role' => $role, 'mobile' => '95000001'.($role === 'admin' ? '10' : '20'), 'notary_approved_at' => now()]);

            $this->actingAs($staff)->post(route('user.mobile.change'), ['mobile' => self::NEW, 'current_password' => 'right-password'])->assertForbidden();
        }
    }

    public function test_the_profile_page_shows_the_right_form_and_masks_numbers(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/user/profile')->assertOk()
            ->assertSee('Change mobile number')->assertSee(SmsService::mask(self::OLD))->assertDontSee(self::OLD);

        $user->forceFill(['pending_mobile' => self::NEW, 'pending_mobile_requested_at' => now()])->save();

        $this->actingAs($user->fresh())->get('/user/profile')->assertOk()
            ->assertSee('Enter the 6-digit code')->assertSee(SmsService::mask(self::NEW))->assertDontSee(self::NEW);
    }

    // ---- Found by independent testing

    public function test_a_wrong_code_and_the_daily_cap_are_audited(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);

        $this->confirm($user, $this->code === '000000' ? '000001' : '000000');

        $this->assertTrue(AuditLog::where('action', 'user.mobile_change_denied')->get()->contains(fn ($entry) => $entry->meta['reason'] === 'invalid'));

        $capped = $this->user(['mobile' => '9500000080']);
        RateLimiter::hit('mobile-change:'.$capped->id, 86400);
        RateLimiter::hit('mobile-change:'.$capped->id, 86400);
        RateLimiter::hit('mobile-change:'.$capped->id, 86400);

        $this->start($capped, ['mobile' => '9500000081']);

        $this->assertTrue(AuditLog::where('action', 'user.mobile_change_denied')->get()->contains(fn ($entry) => $entry->meta['reason'] === 'daily_cap'));
    }

    public function test_an_email_change_warns_the_old_address_so_alerts_cannot_be_redirected_silently(): void
    {
        $user = $this->user();
        $old = $user->email;

        $this->actingAs($user)->put('/user/profile', ['name' => $user->name, 'email' => 'thief@example.test', 'current_password' => 'right-password']);

        Notification::assertSentOnDemand(SecurityAlertNotification::class, fn ($alert, $channels, $notifiable) => $alert->event === 'email_changed' && $notifiable->routes['mail'] === $old);
    }

    public function test_an_alert_to_an_address_alone_still_renders(): void
    {
        $mail = (new SecurityAlertNotification('email_changed'))->toMail(Notification::route('mail', 'old@example.test'));

        $this->assertSame('Hello,', $mail->viewData['greeting'] ?? $mail->greeting ?? 'Hello,');
    }

    public function test_a_held_number_that_someone_else_now_owns_gets_no_more_codes(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);
        $this->sentTo = null;

        $this->user(['mobile' => self::NEW]);
        $this->travel(61)->seconds();

        $this->actingAs($user->fresh())->post(route('user.mobile.change.resend'))->assertSessionHas('error');

        $this->assertNull($this->sentTo);
        $this->assertNull($user->fresh()->pending_mobile);
    }

    public function test_cancelling_leaves_the_codes_of_whoever_now_owns_the_number(): void
    {
        $user = $this->user();
        $this->mockSms();
        $this->start($user);
        $this->user(['mobile' => self::NEW]);

        $this->actingAs($user->fresh())->delete(route('user.mobile.change.cancel'));

        $this->assertSame(1, DB::table('otp_verifications')->where('mobile', self::NEW)->count());
    }

    public function test_a_request_cancelled_while_the_code_is_checked_does_not_activate(): void
    {
        $user = $this->user(['pending_mobile' => self::NEW, 'pending_mobile_requested_at' => now()]);

        $this->partialMock(OtpService::class, function ($mock) use ($user) {
            $mock->shouldReceive('verify')->andReturnUsing(function () use ($user) {
                User::whereKey($user->id)->update(['pending_mobile' => null]);

                return OtpService::OK;
            });
        });

        $this->confirm($user, '123456')->assertSessionHas('error');

        $this->assertSame(self::OLD, $user->fresh()->mobile);
    }

    public function test_an_array_number_is_a_validation_error_not_a_server_error(): void
    {
        $user = $this->user();
        $unverified = $this->user(['mobile' => '9500000090', 'mobile_verified_at' => null]);

        $this->start($user, ['mobile' => ['x']])->assertSessionHasErrors('mobile');
        $this->actingAs($user)->put('/user/profile', ['name' => $user->name, 'email' => $user->email, 'mobile' => ['x']])->assertStatus(302);
        $this->actingAs($unverified)->post('/mobile/number', ['mobile' => ['x'], 'current_password' => 'right-password'])->assertSessionHasErrors('mobile');
    }
}
