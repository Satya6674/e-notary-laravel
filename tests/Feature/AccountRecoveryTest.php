<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\ServiceDraft;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use App\Services\OtpService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** Reads real built messages from the in-memory mailer. No message leaves this process. */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Requested by the account holder in writing.';

    private const OLD_PASSWORD = 'The-old-password1';

    private const NEW_PASSWORD = 'A-brand-new-password1';

    private const EMAIL = 'returning@example.test';

    private const MOBILE = '9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(User::class, 'accountRecoverySupported'))->setValue(null, null);

        parent::tearDown();
    }

    private function person(string $role = 'public', array $values = []): User
    {
        $user = User::factory()->create(array_intersect_key($values, array_flip(['email', 'mobile', 'name'])) + [
            'role' => $role, 'password' => Hash::make(self::OLD_PASSWORD), 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        $user->forceFill(['email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user;
    }

    private function admin(): User
    {
        return $this->person('admin');
    }

    /** A deleted account, removed the way an administrator removes one. */
    private function archived(string $role = 'public', ?User $admin = null): User
    {
        $user = $this->person($role, ['email' => self::EMAIL, 'mobile' => self::MOBILE, 'name' => 'Old Name']);

        app(AccountDeletionService::class)->delete($admin ?? $this->admin(), $user, self::REASON);

        return $user->fresh();
    }

    private function application(User $user, array $values = []): NotaryRequest
    {
        return NotaryRequest::create($values + ['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $user->id, 'document_type' => 'will', 'purpose' => 'Kept application', 'status' => 'completed']);
    }

    private function register(string $email = self::EMAIL, string $mobile = self::MOBILE, string $role = 'public')
    {
        Auth::logout();

        return $this->postJson(route('register.submit'), ['name' => 'New Name', 'email' => $email, 'mobile' => $mobile, 'password' => 'New-account-password1', 'password_confirmation' => 'New-account-password1', 'role' => $role, 'accept_terms' => '1']);
    }

    /** A registration through the real route, then the verification each path would record. */
    private function registered(string $email = self::EMAIL, string $mobile = self::MOBILE, string $mobileMethod = User::METHOD_MOCK_SMS, string $role = 'public'): User
    {
        $this->register($email, $mobile, $role)->assertOk();
        Auth::logout();

        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verified_at' => now(), 'mobile_verification_method' => $mobileMethod, 'mobile_verification_status' => $mobileMethod === User::METHOD_SMS ? User::STATUS_SMS_VERIFIED : User::STATUS_SIMULATED])->save();

        return $user->fresh();
    }

    private function sent(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => $m->getOriginalMessage()->getSubject(), 'body' => $m->getOriginalMessage()->getTextBody()])
            ->values()->all();
    }

    private function subjects(string $to): array
    {
        return array_column(array_filter($this->sent(), fn ($m) => $m['to'] === $to), 'subject');
    }

    private function lastCode(): string
    {
        $mail = collect($this->sent())->last(fn ($m) => str_contains($m['subject'], 'recovery code'));

        preg_match('/recovery code is (\d{6})/', (string) $mail['body'], $found);

        return $found[1];
    }

    private function requestRecovery(User $user): AccountRecoveryRequest
    {
        $this->actingAs($user)->get(route('account.previous'))->assertOk();
        $this->actingAs($user)->post(route('account.previous.send'))->assertSessionHas('status');
        $this->actingAs($user)->post(route('account.previous.confirm'), ['otp' => $this->lastCode()])->assertRedirect(route('account.recovery.status'));

        return AccountRecoveryRequest::where('requester_id', $user->id)->sole();
    }

    private function approve(User $admin, AccountRecoveryRequest $recovery, array $data = [])
    {
        return $this->actingAs($admin)->post(route('admin.recovery.approve', $recovery), $data + ['identity_checked' => '1']);
    }

    private function resetToken(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    private function resetWith(string $email, string $token, string $password)
    {
        return $this->post(route('password.update'), ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    private function loginStatus(string $identifier, string $password): int
    {
        Auth::logout();

        return $this->postJson(route('login.submit'), ['mobile' => $identifier, 'password' => $password])->getStatusCode();
    }

    private function bridgeOn(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true, 'mail.mode' => 'local_gmail', 'services.outbound.mail' => self::EMAIL]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function smsOn(?string &$code = null): void
    {
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
        Http::fake(function ($request) use (&$code) {
            $code = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });
    }

    // ---- Phase 1: Admin-only deletion

    #[DataProvider('nonAdmins')]
    public function test_only_an_admin_can_delete_an_account(string $role): void
    {
        $actor = $this->person($role);
        $target = $this->person();

        $this->actingAs($actor)->delete(route('admin.users.delete', $target), ['reason' => self::REASON, 'confirm' => '1'])->assertForbidden();

        $this->assertFalse($target->fresh()->isDeleted());
    }

    public static function nonAdmins(): array
    {
        return ['citizen' => ['public'], 'notary' => ['notary']];
    }

    public function test_nobody_has_a_way_to_delete_their_own_account(): void
    {
        $citizen = $this->person();
        $admin = $this->admin();
        $this->admin();

        $this->actingAs($citizen)->delete(route('admin.users.delete', $citizen), ['reason' => self::REASON, 'confirm' => '1'])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.users.delete', $admin), ['reason' => self::REASON, 'confirm' => '1'])->assertSessionHas('error');

        $this->assertFalse($citizen->fresh()->isDeleted());
        $this->assertFalse($admin->fresh()->isDeleted());
    }

    // ---- Phase 2: the same email and mobile can register again

    public function test_a_deleted_identity_can_register_again_with_a_new_user_id(): void
    {
        $old = $this->archived();

        $this->register()->assertOk()->assertJsonPath('success', true);

        $new = User::where('email', self::EMAIL)->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(self::MOBILE, $new->mobile);
        $this->assertNull($new->email_verified_at, 'A new account proves its identity afresh.');
        $this->assertNull($new->mobile_verified_at);
    }

    public function test_the_deleted_row_keeps_nothing_that_can_be_found_by_the_real_identity(): void
    {
        $old = $this->archived();
        $record = AccountDeletion::sole();

        $this->assertStringEndsWith('@deleted.invalid', $old->email);
        $this->assertNull($old->mobile);
        $this->assertSame(self::EMAIL, $record->email);
        $this->assertSame(self::MOBILE, $record->mobile);
        $this->assertNotNull($record->identity_released_at);
        $this->assertTrue($old->isDeleted(), 'The account stays archived.');

        $this->assertSame(401, $this->loginStatus(self::EMAIL, self::OLD_PASSWORD));
        $this->assertSame(401, $this->loginStatus(self::MOBILE, self::OLD_PASSWORD));
        $this->assertSame(401, $this->loginStatus($old->email, self::OLD_PASSWORD));
    }

    public function test_a_new_account_receives_nothing_from_the_old_one(): void
    {
        $old = $this->archived();
        $application = $this->application($old);
        $new = $this->registered();

        $this->actingAs($new)->post(route('account.previous.new'))->assertRedirect();

        $this->assertSame(0, $new->requests()->count());
        $this->assertSame($old->id, $application->fresh()->user_id);
        $this->assertTrue($old->fresh()->isDeleted());
        $this->actingAs($new)->get(route('user.requests.show', $application))->assertForbidden();
        $this->actingAs($new)->get(route('user.requests'))->assertOk()->assertDontSee($application->request_number);
        $this->actingAs($new)->get(route('applications.summary', $application))->assertForbidden();
    }

    public function test_active_accounts_still_have_unique_email_and_mobile(): void
    {
        $this->person('public', ['email' => 'taken@example.test', 'mobile' => '9000000001']);

        $this->register('taken@example.test', '9000000002')->assertStatus(422);
        $this->register('free@example.test', '9000000001')->assertStatus(422);

        $this->assertSame(1, User::where('email', 'taken@example.test')->count());
        $this->assertFalse(User::where('email', 'free@example.test')->exists());
    }

    public function test_a_simultaneous_registration_gets_a_normal_error_not_a_server_error(): void
    {
        $this->archived();

        // The other sign-up commits after this one's validation and before its transaction. The
        // password is hashed exactly there, so that is where the rival's row lands.
        Hash::partialMock()->shouldReceive('make')->andReturnUsing(function () {
            DB::table('users')->insert(['name' => 'Rival', 'email' => self::EMAIL, 'mobile' => self::MOBILE, 'password' => 'x', 'role' => 'public', 'created_at' => now(), 'updated_at' => now()]);

            return 'hashed';
        });

        $this->register()->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('message', 'This email or mobile number is already registered.');

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertFalse(Auth::check());
    }

    // ---- Condition 1: a deleted Admin keeps their identity

    public function test_a_deleted_admin_identity_is_not_released(): void
    {
        $admin = $this->person('admin', ['email' => 'old.admin@example.test', 'mobile' => '9000000005']);

        app(AccountDeletionService::class)->delete($this->admin(), $admin, self::REASON);

        $this->assertSame('old.admin@example.test', $admin->fresh()->email);
        $this->assertSame('9000000005', $admin->fresh()->mobile);
        $this->assertNull(AccountDeletion::sole()->identity_released_at);

        $this->register('old.admin@example.test', '9000000006')->assertStatus(422);
        $this->register('someone@example.test', '9000000005')->assertStatus(422);
    }

    public function test_a_deleted_admin_is_never_detected_or_recoverable(): void
    {
        $this->person('admin', ['email' => 'old.admin@example.test', 'mobile' => '9000000005']);
        $target = User::where('email', 'old.admin@example.test')->sole();
        app(AccountDeletionService::class)->delete($this->admin(), $target, self::REASON);

        // Even a record that says released must not be offered.
        AccountDeletion::sole()->update(['identity_released_at' => now()]);
        $person = $this->person('public', ['email' => 'OLD.ADMIN@example.test.invalid', 'mobile' => '9000000007']);
        DB::table('users')->where('id', $target->id)->update(['email' => 'held@deleted.invalid']);
        DB::table('users')->where('id', $person->id)->update(['email' => 'old.admin@example.test']);

        $this->assertNull(app(AccountRecoveryService::class)->detect($person->fresh()));
    }

    public function test_restoring_a_deleted_admin_leaves_their_identity_alone(): void
    {
        $admin = $this->person('admin', ['email' => 'old.admin@example.test', 'mobile' => '9000000005']);
        $actor = $this->admin();

        app(AccountDeletionService::class)->delete($actor, $admin, self::REASON);
        app(AccountDeletionService::class)->restore($actor, $admin->fresh(), self::REASON);

        $this->assertSame('old.admin@example.test', $admin->fresh()->email);
        $this->assertSame('9000000005', $admin->fresh()->mobile);
    }

    public function test_a_deletion_recorded_before_this_feature_keeps_its_mobile_on_restore(): void
    {
        $actor = $this->admin();
        $legacy = $this->person('public', ['mobile' => '9000000008']);
        $legacy->forceFill(['deleted_at' => now(), 'deactivated_at' => now()])->save();
        AccountDeletion::create(['reference' => 'DEL-LEGACY', 'user_id' => $legacy->id, 'deleted_by' => $actor->id, 'name' => $legacy->name, 'email' => $legacy->email, 'role' => 'public', 'reason' => self::REASON, 'deleted_at' => now()]);

        app(AccountDeletionService::class)->restore($actor, $legacy->fresh(), self::REASON);

        $this->assertSame('9000000008', $legacy->fresh()->mobile, 'A record with no stored identity must not wipe the real one.');
    }

    // ---- Phase 3: detecting a previous account

    public function test_nothing_is_revealed_before_verification(): void
    {
        $this->archived();
        $this->register()->assertOk()->assertDontSee('Welcome Back')->assertDontSee('previous');

        $user = User::where('email', self::EMAIL)->sole();

        $this->assertNull($user->accountChoiceRoute());
        $this->actingAs($user)->get(route('account.previous'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('account.previous.code'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->post(route('account.previous.send'))->assertRedirect(route('verification.notice'));
        $this->assertSame(0, AccountRecoveryRequest::count());
    }

    public function test_looking_before_verification_does_not_use_up_the_one_check(): void
    {
        $this->archived();
        $this->register()->assertOk();
        Auth::logout();
        $user = User::where('email', self::EMAIL)->sole();

        // Any page view asks this. Nothing can be known yet, so nothing may be decided yet.
        $this->assertNull($user->accountChoiceRoute());
        $this->assertSame(User::CHOICE_CHECK, $user->fresh()->previous_account_choice);

        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verified_at' => now(), 'mobile_verification_method' => User::METHOD_MOCK_SMS, 'mobile_verification_status' => User::STATUS_SIMULATED])->save();

        $this->assertSame('account.previous', $user->fresh()->accountChoiceRoute());
    }

    public function test_a_verified_person_is_shown_the_choice_without_any_old_details(): void
    {
        $old = $this->archived();
        $this->application($old);
        $new = $this->registered();

        $this->actingAs($new)->get(route('dashboard'))->assertRedirect(route('account.previous'));

        $page = $this->actingAs($new)->get(route('account.previous'))->assertOk()
            ->assertSee('Welcome Back!')
            ->assertSee('We found a previous E-Notary account associated with your verified email/mobile number. What would you like to do?')
            ->assertSee('Recover my old account and data')
            ->assertSee('Create a completely new account');

        $page->assertDontSee('Old Name')->assertDontSee(self::REASON)->assertDontSee('Kept application');
    }

    public function test_the_address_is_matched_whatever_its_capitals(): void
    {
        $this->archived();
        $new = $this->registered('Returning@Example.Test');

        $this->assertSame('account.previous', $new->accountChoiceRoute());
    }

    public function test_a_login_lands_on_the_choice(): void
    {
        $this->archived();
        $this->registered();
        Auth::logout();

        $this->postJson(route('login.submit'), ['mobile' => self::EMAIL, 'password' => 'New-account-password1'])->assertOk()->assertJsonPath('redirect', route('account.previous'));
    }

    public function test_a_notary_registrant_reaches_the_choice_rather_than_a_refusal(): void
    {
        $this->archived();
        $new = $this->registered(self::EMAIL, self::MOBILE, User::METHOD_MOCK_SMS, 'notary');

        $this->actingAs($new)->get(route('notary.dashboard'))->assertRedirect(route('account.previous'));
    }

    public function test_a_notary_who_verifies_through_the_local_bridge_lands_on_the_choice(): void
    {
        $this->archived('notary');
        $this->bridgeOn();
        $this->register(self::EMAIL, self::MOBILE, 'notary')->assertOk();
        $user = User::where('email', self::EMAIL)->sole();

        $this->actingAs($user)->post(route('mobile.verification.send'))->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', (string) collect($this->sent())->last(fn ($m) => str_contains($m['subject'], 'test code'))['body'], $found);

        // A pending Notary has no dashboard to catch them, so this page must be the one.
        $this->actingAs($user->fresh())->post(route('mobile.verification.verify'), ['otp' => $found[1]])->assertRedirect(route('account.previous'));
    }

    public function test_an_account_with_no_previous_identity_is_checked_once_and_left_alone(): void
    {
        $this->archived();
        $new = $this->registered('someone.else@example.test', '9000000011');

        $this->actingAs($new)->get(route('dashboard'))->assertOk();
        $this->assertSame(User::CHOICE_NONE, $new->fresh()->previous_account_choice);
    }

    public function test_an_existing_account_is_never_looked_at(): void
    {
        $this->archived();
        $existing = $this->person('public', ['email' => 'existing@example.test', 'mobile' => '9000000012']);

        $this->assertNull($existing->previous_account_choice);
        $this->actingAs($existing)->get(route('dashboard'))->assertOk();
        $this->assertNull($existing->fresh()->previous_account_choice, 'Accounts from before this feature must not change.');
    }

    public function test_choosing_a_new_account_is_final_and_keeps_the_old_one_archived(): void
    {
        $old = $this->archived();
        $new = $this->registered();

        $this->actingAs($new)->post(route('account.previous.new'))->assertRedirect(route('dashboard'));

        $this->actingAs($new->fresh())->get(route('dashboard'))->assertOk();
        $this->actingAs($new->fresh())->get(route('account.previous'))->assertRedirect(route('dashboard'));
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(0, AccountRecoveryRequest::count());
        $this->assertTrue(AuditLog::where('action', 'account.previous_declined')->where('model_id', $new->id)->exists());
    }

    public function test_the_old_account_cannot_be_restored_over_a_new_one(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();

        $this->actingAs($admin)->post(route('admin.users.restore', $old), ['reason' => self::REASON])->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(self::EMAIL, $new->fresh()->email);
    }

    public function test_the_choice_goes_away_if_the_old_account_stops_being_archived(): void
    {
        $old = $this->archived();
        $new = $this->registered('other@example.test', '9000000013');
        DB::table('users')->where('id', $new->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);

        $this->assertNull($new->fresh()->accountChoiceRoute());
        $this->assertSame(User::CHOICE_NONE, $new->fresh()->previous_account_choice);
        $this->assertTrue($old->fresh()->isDeleted());
    }

    // ---- Option A: a fresh code, then a request

    public function test_a_request_needs_a_fresh_code_and_is_only_pending(): void
    {
        $old = $this->archived();
        $new = $this->registered();

        $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => '123456'])->assertSessionHasErrors('otp');
        $this->assertSame(0, AccountRecoveryRequest::count());

        $recovery = $this->requestRecovery($new);

        $this->assertSame(AccountRecoveryRequest::PENDING, $recovery->status);
        $this->assertSame($old->id, $recovery->old_user_id);
        $this->assertSame($new->id, $recovery->requester_id);
        $this->assertTrue($old->fresh()->isDeleted(), 'Nothing is restored before an administrator decides.');
        $this->actingAs($new->fresh())->get(route('account.recovery.status'))->assertOk()
            ->assertSee('Recovery pending Admin approval')->assertSee($recovery->reference)->assertDontSee('Old Name');
    }

    public function test_the_code_is_never_on_the_page_in_the_session_or_in_the_queue(): void
    {
        $this->archived();
        $new = $this->registered();
        config(['queue.default' => 'database']);

        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('status');
        $code = $this->lastCode();

        $this->actingAs($new)->get(route('account.previous.code'))->assertOk()->assertDontSee($code);
        $this->assertStringNotContainsString($code, json_encode(session()->all()));
        $this->assertStringNotContainsString($code, (string) DB::table('jobs')->pluck('payload')->implode(''));
        $this->assertStringNotContainsString($code, (string) DB::table('audit_logs')->pluck('meta')->implode(''));
        $this->assertStringNotContainsString($code, (string) MessageDelivery::pluck('recipient')->implode(''));
    }

    public function test_a_code_from_another_flow_cannot_start_a_request_and_a_recovery_code_opens_nothing_else(): void
    {
        $service = app(OtpService::class);

        $this->smsOn($smsCode);
        $service->issue('9000000020', null, 'mobile_verification');
        // Module 6: no recovery code exists for this number, so the answer is "expired" (no valid
        // code for this step) rather than a wrong guess; it is refused either way.
        $this->assertSame(OtpService::EXPIRED, $service->verifyRecoveryCode('9000000020', $smsCode));

        $this->travel(2)->minutes();
        $code = null;
        $service->issueRecoveryEmail('9000000021', null, function (string $issued) use (&$code) {
            $code = $issued;

            return true;
        });

        $this->assertSame(OtpService::EXPIRED, $service->verify('9000000021', $code));
        $this->assertSame(OtpService::EXPIRED, $service->verify('9000000021', $code, true));
        $this->assertSame(OtpService::OK, $service->verifyRecoveryCode('9000000021', $code));
        $this->assertSame(OtpService::EXPIRED, $service->verifyRecoveryCode('9000000021', $code), 'One use only.');
    }

    public function test_repeated_wrong_codes_lock_the_step(): void
    {
        $this->archived();
        $new = $this->registered();
        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('status');
        $code = $this->lastCode();
        $wrong = $code === '111111' ? '222222' : '111111';

        foreach (range(1, OtpService::MAX_ATTEMPTS) as $attempt) {
            $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => $wrong])->assertSessionHasErrors('otp');
        }

        $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertSame(0, AccountRecoveryRequest::count());
        // Each wrong code, and the correct one tried during the lock-out.
        $this->assertSame(OtpService::MAX_ATTEMPTS + 1, AuditLog::where('action', 'auth.otp_failed')->where('model_id', $new->id)->count());
    }

    public function test_an_address_outside_the_allowlist_gets_no_code_and_no_usable_one(): void
    {
        $this->archived();
        $new = $this->registered();
        config(['services.outbound.mail' => 'someone.else@example.test']);
        $before = count($this->sent());

        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('error');

        $this->assertCount($before, $this->sent());
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', self::MOBILE)->where('expires_at', '>', now())->count());
    }

    public function test_a_held_account_has_no_portal_access(): void
    {
        $this->archived();
        $new = $this->registered();
        $this->requestRecovery($new);
        $held = $new->fresh();

        foreach (['dashboard', 'user.documents', 'user.requests', 'user.profile', 'support.index', 'notifications.index', 'settings.edit', 'appointments.index', 'privacy-center.index'] as $route) {
            $this->actingAs($held)->get(route($route))->assertRedirect(route('account.recovery.status'));
        }

        $this->actingAs($held)->put(route('user.profile.update'), ['name' => 'x', 'email' => 'moved@example.test'])->assertRedirect(route('account.recovery.status'));
        $this->assertSame(self::EMAIL, $held->fresh()->email);
    }

    public function test_steps_cannot_be_reached_out_of_order(): void
    {
        $this->archived();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);
        $held = $new->fresh();

        // Held: "continue with a new account" must not release the hold.
        $this->actingAs($held)->post(route('account.previous.new'))->assertRedirect(route('account.recovery.status'));
        $this->assertSame(User::CHOICE_RECOVERY, $held->fresh()->previous_account_choice);
        $this->actingAs($held)->post(route('account.previous.confirm'), ['otp' => '123456'])->assertRedirect(route('account.recovery.status'));
        $this->assertSame(1, AccountRecoveryRequest::count());

        // Finished: cancelling again changes nothing.
        $this->actingAs($held)->post(route('account.recovery.cancel'))->assertRedirect(route('dashboard'));
        $this->assertSame(AccountRecoveryRequest::CANCELLED, $recovery->fresh()->status);
        $this->actingAs($held->fresh())->post(route('account.recovery.cancel'))->assertRedirect(route('dashboard'));
        $this->actingAs($held->fresh())->get(route('account.recovery.status'))->assertRedirect(route('dashboard'));
        $this->assertSame(1, AuditLog::where('action', 'account.recovery_cancelled')->count());
    }

    public function test_a_person_can_cancel_and_keep_their_new_account(): void
    {
        $old = $this->archived();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        $this->actingAs($new->fresh())->post(route('account.recovery.cancel'))->assertRedirect(route('dashboard'));

        $this->assertSame(AccountRecoveryRequest::CANCELLED, $recovery->fresh()->status);
        $this->assertNull($recovery->fresh()->open_key);
        $this->actingAs($new->fresh())->get(route('dashboard'))->assertOk();
        $this->assertTrue($old->fresh()->isDeleted());
    }

    // ---- Local verification is not ownership

    public function test_the_local_bridge_never_matches_a_mobile_number(): void
    {
        $this->archived();
        $this->bridgeOn();

        $this->register('different@example.test', self::MOBILE)->assertOk();
        $user = User::where('email', 'different@example.test')->sole();
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_status' => User::STATUS_SIMULATED, 'mobile_simulated_at' => now()])->save();

        $this->assertTrue($user->fresh()->mobileSimulationAccepted());
        $this->assertNull(app(AccountRecoveryService::class)->detect($user->fresh()));
        $this->assertNull($user->fresh()->accountChoiceRoute());
    }

    public function test_a_mock_sms_never_matches_a_mobile_number(): void
    {
        $this->archived();
        $user = $this->registered('different@example.test', self::MOBILE, User::METHOD_MOCK_SMS);

        $this->assertNull(app(AccountRecoveryService::class)->detect($user));
    }

    public function test_a_genuine_sms_matches_a_mobile_number(): void
    {
        $old = $this->archived();
        $user = $this->registered('different@example.test', self::MOBILE, User::METHOD_SMS);

        $this->assertSame($old->id, app(AccountRecoveryService::class)->detect($user)?->user_id);
    }

    public function test_an_unverified_address_never_matches(): void
    {
        $this->archived();
        $this->register()->assertOk();
        $user = User::where('email', self::EMAIL)->sole();

        $this->assertNull(app(AccountRecoveryService::class)->detect($user));
    }

    public function test_a_local_request_is_labelled_as_not_genuine_proof(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->bridgeOn();
        $this->register()->assertOk();
        $user = User::where('email', self::EMAIL)->sole();
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_status' => User::STATUS_SIMULATED, 'mobile_simulated_at' => now()])->save();

        $page = $this->actingAs($user->fresh())->get(route('account.previous'))->assertOk();
        $page->assertSee('simulated, not genuine proof of ownership');

        $recovery = $this->requestRecovery($user->fresh());

        $this->assertSame(AccountRecoveryRequest::ENV_LOCAL, $recovery->environment);
        $this->assertSame(AccountRecoveryRequest::PROOF_EMAIL_LOCAL, $recovery->email_proof);
        $this->assertSame(AccountRecoveryRequest::PROOF_SIMULATED, $recovery->mobile_proof);
        $this->assertNull($user->fresh()->mobile_verified_at, 'The bridge never records a verified mobile.');

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertOk()
            ->assertSee('Local development request. The email and mobile checks were simulated, so this is not genuine proof of ownership.')
            ->assertSee('Simulated by the local email bridge. Not proof of the phone')
            ->assertSee('Local check only: a code was typed, but this is not genuine proof of the mailbox');
    }

    public function test_a_site_that_is_not_production_is_never_labelled_production(): void
    {
        // No bridge here, as on the mock SMS site.
        $this->archived();
        $new = $this->registered();

        $recovery = $this->requestRecovery($new);

        $this->assertSame(AccountRecoveryRequest::ENV_LOCAL, $recovery->environment);
        $this->assertSame(AccountRecoveryRequest::PROOF_MOCK_SMS, $recovery->mobile_proof);
    }

    public function test_only_production_records_genuine_proof(): void
    {
        $this->archived();
        $new = $this->registered(self::EMAIL, self::MOBILE, User::METHOD_SMS);
        $this->app['env'] = 'production';
        // CSRF checks return outside the testing environment.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $recovery = $this->requestRecovery($new);

        $this->assertSame(AccountRecoveryRequest::ENV_PRODUCTION, $recovery->environment);
        $this->assertSame(AccountRecoveryRequest::PROOF_EMAIL_CODE, $recovery->email_proof);
        $this->assertSame(AccountRecoveryRequest::PROOF_GENUINE_SMS, $recovery->mobile_proof);
    }

    // ---- Phase 4: administrators

    public function test_only_an_active_admin_reaches_the_requests(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        foreach (['public', 'notary'] as $role) {
            $actor = $this->person($role, ['email' => "$role@example.test", 'mobile' => $role === 'public' ? '9000000031' : '9000000032']);

            $this->actingAs($actor)->get(route('admin.recovery.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.recovery.show', $recovery))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.recovery.review', $recovery))->assertForbidden();
            $this->approve($actor, $recovery)->assertForbidden();
            $this->actingAs($actor)->post(route('admin.recovery.reject', $recovery), ['reason' => self::REASON])->assertForbidden();
            $this->actingAs($actor)->post(route('admin.recovery.cancel', $recovery), ['reason' => self::REASON])->assertForbidden();
            $this->actingAs($actor)->post(route('admin.recovery.retry', [$recovery, 'approved']))->assertForbidden();
        }

        Auth::logout();
        $this->get(route('admin.recovery.index'))->assertRedirect(route('login'));

        $this->assertSame(AccountRecoveryRequest::PENDING, $recovery->fresh()->status);

        $deactivated = $this->admin();
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        $this->assertTrue(Gate::forUser($deactivated)->denies('review', $recovery));
        $this->assertTrue(Gate::forUser($admin)->allows('review', $recovery));
    }

    public function test_an_admin_cannot_decide_a_request_involving_their_own_account(): void
    {
        $this->archived();
        $recovery = $this->requestRecovery($this->registered());
        $requester = $recovery->requester;

        $requester->forceFill(['role' => 'admin'])->save();

        $this->assertTrue(Gate::forUser($requester->fresh())->denies('review', $recovery));
    }

    public function test_the_list_masks_contact_details_and_shows_the_request(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->get(route('admin.recovery.index'))->assertOk()
            ->assertSee($recovery->reference)->assertSee('New Name')->assertSee('Pending')->assertSee('Citizen')
            ->assertSee((string) $old->id)
            ->assertDontSee(self::EMAIL)->assertDontSee(self::MOBILE);
    }

    public function test_the_detail_page_shows_the_deletion_history(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertOk()
            ->assertSee(AccountDeletion::sole()->reference)->assertSee(self::REASON)
            ->assertSee('No conflict.');
    }

    public function test_a_request_can_be_marked_under_review(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->post(route('admin.recovery.review', $recovery))->assertSessionHas('status');

        $this->assertSame(AccountRecoveryRequest::UNDER_REVIEW, $recovery->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'account.recovery_under_review')->where('user_id', $admin->id)->exists());
    }

    public function test_rejection_needs_a_reason_and_tells_the_person(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        $this->actingAs($admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'short'])->assertSessionHasErrors('reason');
        $this->assertSame(AccountRecoveryRequest::PENDING, $recovery->fresh()->status);

        $this->actingAs($admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'The documents supplied did not match.'])->assertSessionHas('status');

        $recovery = $recovery->fresh();
        $this->assertSame(AccountRecoveryRequest::REJECTED, $recovery->status);
        $this->assertSame($admin->id, $recovery->reviewed_by);
        $this->assertNotNull($recovery->reviewed_at);
        $this->assertNull($recovery->open_key);
        $this->assertTrue($old->fresh()->isDeleted());

        $mail = collect($this->sent())->last();
        $this->assertSame(self::EMAIL, $mail['to']);
        $this->assertStringContainsString('The documents supplied did not match.', $mail['body']);
        $this->actingAs($new->fresh())->get(route('dashboard'))->assertOk();
        $this->assertTrue(AuditLog::where('action', 'account.recovery_rejected')->where('user_id', $admin->id)->exists());
    }

    public function test_approval_restores_the_original_account_with_its_records(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $application = $this->application($old);
        $document = Document::create(['request_id' => $application->id, 'title' => 'Kept', 'original_file' => 'documents/a.pdf', 'verification_code' => 'KEEPKEEPKEEPKEEP', 'mime_type' => 'application/pdf', 'file_size' => 1]);
        $auditBefore = AuditLog::count();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        $this->approve($admin, $recovery)->assertSessionHas('status');

        $restored = $old->fresh();
        $this->assertFalse($restored->isDeleted());
        $this->assertSame(self::EMAIL, $restored->email);
        $this->assertSame(self::MOBILE, $restored->mobile);
        $this->assertSame('Old Name', $restored->name);
        $this->assertNotNull($restored->email_verified_at);
        $this->assertSame($old->id, $application->fresh()->user_id);
        $this->assertSame($application->id, $document->fresh()->request_id);
        $this->assertTrue($new->fresh()->isDeleted());
        $this->assertStringEndsWith('@deleted.invalid', $new->fresh()->email);

        $this->assertSame(AccountRecoveryRequest::APPROVED, $recovery->fresh()->status);
        $this->assertSame($admin->id, $recovery->fresh()->reviewed_by);
        $this->assertGreaterThan($auditBefore, AuditLog::count());
        $this->assertTrue(AuditLog::where('action', 'account.recovery_approved')->where('user_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.user_deleted')->where('model_id', $new->id)->exists());
    }

    public function test_approval_requires_the_identity_tick(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->post(route('admin.recovery.approve', $recovery))->assertSessionHasErrors('identity_checked');

        $this->assertTrue($old->fresh()->isDeleted());
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        $this->actingAs($admin)->post(route('admin.recovery.reject', $recovery), ['reason' => self::REASON]);

        $this->approve($admin, $recovery->fresh())->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'A second, different reason.'])->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(self::REASON, $recovery->fresh()->decision_reason);
    }

    public function test_a_restored_account_is_not_asked_about_the_account_it_replaced(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $restored = $old->fresh();

        $this->assertNull($restored->accountChoiceRoute());
        $this->assertSame(User::CHOICE_NONE, $restored->previous_account_choice);
    }

    public function test_the_replaced_blank_account_is_not_offered_to_later_registrants(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        // The restored owner moves to another address, freeing the old one.
        $old->fresh()->forceFill(['email' => 'moved@example.test', 'mobile' => '9000000040'])->save();

        $later = $this->registered(self::EMAIL, '9000000041');

        $this->assertNull(app(AccountRecoveryService::class)->detect($later));
    }

    public function test_deleting_the_requester_ends_their_open_request(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        app(AccountDeletionService::class)->delete($admin, $new->fresh(), self::REASON);

        $this->assertSame(AccountRecoveryRequest::CANCELLED, $recovery->fresh()->status);
        $this->assertNull($recovery->fresh()->open_key);
        $this->approve($admin, $recovery->fresh())->assertSessionHas('error');
        $this->assertTrue($old->fresh()->isDeleted());
    }

    public function test_the_old_account_cannot_be_restored_around_an_open_request(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->requestRecovery($this->registered());

        $this->actingAs($admin)->post(route('admin.users.restore', $old), ['reason' => self::REASON])->assertSessionHas('error', 'This account has an open recovery request. Decide it under Account Recovery Requests.');

        $this->assertTrue($old->fresh()->isDeleted());
    }

    public function test_the_old_account_cannot_be_restored_over_the_same_address_in_other_capitals(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered('RETURNING@example.test', '9000000110');

        $this->actingAs($admin)->post(route('admin.users.restore', $old), ['reason' => self::REASON])->assertSessionHas('error', 'Another active account now holds this email or mobile number, so it cannot be restored here.');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($new->fresh()->isDeleted());
    }

    // ---- Condition 2: a new password, through the email link only

    public function test_the_old_password_stops_working_and_no_other_way_in_exists(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $restored = $old->fresh();

        $this->assertTrue($restored->mustResetPassword());
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $restored->password));
        $this->assertSame(401, $this->loginStatus(self::EMAIL, self::OLD_PASSWORD));
        $this->assertSame(401, $this->loginStatus(self::MOBILE, self::OLD_PASSWORD));
        $this->assertSame(401, $this->loginStatus(self::EMAIL, 'New-account-password1'));
        $this->assertFalse($restored->canRecoverByMobile(), 'SMS must not get around the emailed link.');
    }

    public function test_a_number_that_stays_verified_still_cannot_get_around_the_emailed_link(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered(self::EMAIL, self::MOBILE, User::METHOD_SMS)));
        $this->smsOn($code);
        $restored = $old->fresh();

        $this->assertTrue($restored->hasVerifiedMobile(), 'This number was proven by the request, so this test reaches the rule itself.');
        $this->assertFalse($restored->canRecoverByMobile());

        Auth::logout();
        $this->post(route('password.email'), ['identifier' => self::MOBILE])->assertSessionHas('status');

        $this->assertNull($code, 'No SMS code may stand in for the emailed link.');
    }

    public function test_a_live_session_ends_while_a_new_password_is_needed(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $this->actingAs($old->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_emailed_link_sets_a_new_password_and_ends_the_requirement(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $mail = collect($this->sent())->last(fn ($m) => str_contains($m['subject'], 'Reset your'));
        $this->assertSame(self::EMAIL, $mail['to']);
        preg_match('#/reset-password/([^?\s]+)#', $mail['body'], $link);

        $this->resetWith(self::EMAIL, $link[1], self::NEW_PASSWORD)->assertRedirect(route('home'))->assertSessionHas('status');

        $restored = $old->fresh();
        $this->assertFalse($restored->mustResetPassword());
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $restored->password));
        $this->assertSame(200, $this->loginStatus(self::EMAIL, self::NEW_PASSWORD));
    }

    public function test_the_old_password_is_refused_on_reset_only_for_a_valid_link(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));
        $restored = $old->fresh();

        // No token: the same answer as any other password, so this confirms nothing.
        $this->resetWith(self::EMAIL, 'not-a-real-token', self::OLD_PASSWORD)->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('password');

        $this->resetWith(self::EMAIL, $this->resetToken($restored), self::OLD_PASSWORD)->assertSessionHasErrors('password');

        $this->assertTrue($restored->fresh()->mustResetPassword());
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $restored->fresh()->password));
    }

    public function test_the_old_password_cannot_return_later(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered(self::EMAIL, self::MOBILE, User::METHOD_SMS)));
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);
        $restored = $old->fresh();

        $this->actingAs($restored)->put(route('user.profile.update'), ['name' => 'Old Name', 'email' => self::EMAIL, 'mobile' => self::MOBILE, 'current_password' => self::NEW_PASSWORD, 'password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $restored->fresh()->password));
    }

    public function test_a_notary_cannot_bring_the_old_password_back_either(): void
    {
        $old = $this->archived('notary');
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);
        $this->actingAs($admin)->post(route('admin.notaries.approve', $old));

        $this->actingAs($old->fresh())->post(route('notary.profile.password'), ['current_password' => self::NEW_PASSWORD, 'password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $old->fresh()->password));
    }

    public function test_an_admin_cannot_set_the_password_of_a_recovered_account(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $this->actingAs($admin)->put(route('admin.users.update', $old), ['name' => 'Old Name', 'email' => self::EMAIL, 'mobile' => self::MOBILE, 'role' => 'public', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertSessionHasErrors('password');

        $this->assertTrue($old->fresh()->mustResetPassword());
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $old->fresh()->password));
    }

    public function test_a_second_recovery_keeps_the_real_old_password_refused(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        app(AccountDeletionService::class)->delete($admin, $old->fresh(), self::REASON);
        $this->travel(2)->minutes();
        $again = $this->registered();
        $this->approve($admin, $this->requestRecovery($again));

        $this->assertTrue($old->fresh()->isRetiredPassword(self::OLD_PASSWORD));
        $this->assertCount(1, $old->fresh()->retired_passwords, 'The random placeholder was never a real password.');
    }

    public function test_the_retired_password_never_leaves_the_server(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $this->assertArrayNotHasKey('retired_passwords', $old->fresh()->toArray());
    }

    public function test_every_earlier_password_stays_refused_after_a_second_recovery(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);

        app(AccountDeletionService::class)->delete($admin, $old->fresh(), self::REASON);
        $this->travel(2)->minutes();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $this->assertTrue($old->fresh()->isRetiredPassword(self::OLD_PASSWORD));
        $this->assertTrue($old->fresh()->isRetiredPassword(self::NEW_PASSWORD));
    }

    public function test_the_address_cannot_move_before_the_emailed_reset_is_used(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $this->actingAs($admin)->put(route('admin.users.update', $old), ['name' => 'Old Name', 'email' => 'attacker@example.test', 'mobile' => self::MOBILE, 'role' => 'public'])->assertSessionHasErrors('email');

        $this->assertSame(self::EMAIL, $old->fresh()->email);

        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);
        $this->actingAs($admin)->put(route('admin.users.update', $old), ['name' => 'Old Name', 'email' => 'moved@example.test', 'mobile' => self::MOBILE, 'role' => 'public'])->assertSessionHasNoErrors();

        $this->assertSame('moved@example.test', $old->fresh()->email);
    }

    // ---- A phone may have changed hands

    public function test_a_stranger_holding_a_reissued_number_cannot_take_the_recovered_account(): void
    {
        // Whoever holds the old number proved it by genuine SMS, on their own account.
        [$old, $admin, $holder, $recovery] = $this->conflict();
        $this->smsOn($code);

        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('status');
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);

        $restored = $old->fresh();
        $this->assertSame(self::MOBILE, $restored->mobile);
        $this->assertNull($restored->mobile_verified_at);
        $this->assertNull($restored->mobile_verification_method);
        $this->assertFalse($restored->canRecoverByMobile());

        // The stranger tries the SMS reset with the number now back on the account.
        Auth::logout();
        $this->post(route('password.email'), ['identifier' => self::MOBILE])->assertSessionHas('status');

        $this->assertNull($code, 'No code may go to a phone this account has not proven again.');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $restored->fresh()->password));
    }

    public function test_a_number_the_request_proved_by_genuine_sms_stays_verified(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered(self::EMAIL, self::MOBILE, User::METHOD_SMS)));
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);

        $this->assertNotNull($old->fresh()->mobile_verified_at);
        $this->assertTrue($old->fresh()->canRecoverByMobile());
    }

    public function test_a_different_verified_number_on_the_request_does_not_vouch_for_the_old_one(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered(self::EMAIL, '9000000100', User::METHOD_SMS)));

        $this->assertNull($old->fresh()->mobile_verified_at);
    }

    public function test_a_citizen_with_an_unproven_number_proves_it_again_and_is_told(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);

        $this->actingAs($old->fresh())->get(route('dashboard'))->assertRedirect(route('verification.mobile.notice'));

        $mail = collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'has been restored'));
        $this->assertStringContainsString('confirm your mobile number again', $mail['body']);
    }

    public function test_a_number_the_old_account_never_proved_matches_nothing(): void
    {
        $old = $this->archived();
        DB::table('users')->where('id', $old->id)->update(['mobile_verified_at' => null]);
        $stranger = $this->registered('stranger@example.test', self::MOBILE, User::METHOD_SMS);

        $this->assertNull(app(AccountRecoveryService::class)->detect($stranger));
    }

    public function test_a_number_the_old_account_proved_only_by_mock_matches_nothing(): void
    {
        $old = $this->archived();
        DB::table('users')->where('id', $old->id)->update(['mobile_verification_method' => User::METHOD_MOCK_SMS]);
        $stranger = $this->registered('stranger@example.test', self::MOBILE, User::METHOD_SMS);

        $this->assertNull(app(AccountRecoveryService::class)->detect($stranger));
    }

    public function test_an_email_match_does_not_depend_on_the_old_mobile_proof(): void
    {
        $old = $this->archived();
        DB::table('users')->where('id', $old->id)->update(['mobile_verified_at' => null]);

        $this->assertSame($old->id, app(AccountRecoveryService::class)->detect($this->registered())?->user_id);
    }

    public function test_a_reset_link_issued_to_the_replaced_account_is_dead(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $token = $this->resetToken($new);
        $this->approve($admin, $this->requestRecovery($new));

        $this->resetWith(self::EMAIL, $token, self::NEW_PASSWORD)->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $old->fresh()->password));
    }

    // ---- Condition 3: a recovered Notary waits

    public function test_a_recovered_notary_stays_pending_until_approved_again(): void
    {
        $old = $this->archived('notary');
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertSee('Approving does not restore Notary access.');
        $this->approve($admin, $recovery);
        $this->assertNull($old->fresh()->notary_approved_at);

        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);
        $restored = $old->fresh();

        $this->assertFalse($restored->isApprovedNotary());
        // Module 1: the work area sends a pending Notary to the waiting page, never to the work.
        $this->actingAs($restored)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));
        $this->actingAs($admin)->get(route('admin.notaries.index', ['status' => 'pending']))->assertSee(self::EMAIL);

        $this->actingAs($admin)->post(route('admin.notaries.approve', $restored))->assertRedirect();
        $this->actingAs($restored->fresh())->get(route('notary.dashboard'))->assertOk();
    }

    public function test_a_citizen_who_registers_as_a_notary_gets_no_notary_rights_from_recovery(): void
    {
        $old = $this->archived('public');
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered(self::EMAIL, self::MOBILE, User::METHOD_MOCK_SMS, 'notary');

        $this->approve($admin, $this->requestRecovery($new));

        $this->assertSame('public', $old->fresh()->role);
    }

    // ---- Condition 4: the old address hears about a mobile match

    public function test_a_mobile_match_alerts_the_old_address_with_nothing_about_the_requester(): void
    {
        $this->archived();
        $this->smsOn($code);
        $new = $this->registered('different@example.test', self::MOBILE, User::METHOD_SMS);

        // Module 4: the code goes to the previous account's address, never to the phone.
        $this->actingAs($new)->post(route('account.previous.send'))->assertSessionHas('status');
        $this->assertNull($code, 'No SMS: a phone alone never authorizes a recovery.');
        $this->assertSame(['Your E-Notary account recovery code'], array_values(array_filter($this->subjects(self::EMAIL), fn ($s) => str_contains($s, 'recovery code'))));
        $this->assertSame([], array_values(array_filter($this->subjects('different@example.test'), fn ($s) => str_contains($s, 'recovery code'))));

        $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => $this->lastCode()])->assertRedirect(route('account.recovery.status'));

        $recovery = AccountRecoveryRequest::sole();
        $this->assertSame('mobile', $recovery->match_type);
        $this->assertNotSame(AccountRecoveryRequest::PROOF_NONE, $recovery->email_proof, 'The previous mailbox was proven.');
        $this->assertSame(AccountRecoveryRequest::PROOF_GENUINE_SMS, $recovery->mobile_proof);
        $this->assertSame('previous_account_address', AuditLog::where('action', 'account.recovery_requested')->sole()->meta['code_sent_to']);

        $alert = collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'Security alert'));
        $this->assertSame(self::EMAIL, $alert['to']);
        $this->assertStringContainsString('different email address', $alert['body']);
        $this->assertStringNotContainsString('different@example.test', $alert['body']);
        $this->assertStringNotContainsString(self::MOBILE, $alert['body']);
        $this->assertStringContainsString('sent', $recovery->noticeStatus('alert'));
    }
    public function test_an_email_match_sends_no_alert(): void
    {
        $this->archived();
        $recovery = $this->requestRecovery($this->registered());

        $this->assertNull($recovery->alert_notification_id);
        $this->assertSame([], array_filter($this->sent(), fn ($m) => str_contains($m['subject'], 'Security alert')));
    }

    public function test_a_mobile_match_resets_through_the_old_address_not_the_new_one(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered('different@example.test', self::MOBILE, User::METHOD_SMS);
        // The code went to the previous account's address (Module 4), and is read from there.
        $this->actingAs($new)->post(route('account.previous.send'));
        $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => $this->lastCode()]);

        $this->approve($admin, AccountRecoveryRequest::sole());

        $this->assertSame(self::EMAIL, $old->fresh()->email);
        $reset = collect($this->sent())->last(fn ($m) => str_contains($m['subject'], 'Reset your'));
        $this->assertSame(self::EMAIL, $reset['to']);
        $this->assertSame([], array_values(array_filter($this->subjects('different@example.test'), fn ($s) => str_contains($s, 'Reset your'))));
    }

    // ---- Condition 5: separate emails

    public function test_approval_and_restoration_are_separate_emails(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        $before = count($this->sent());

        $this->approve($admin, $recovery);

        $mails = array_slice($this->sent(), $before);
        $subjects = array_column($mails, 'subject');

        $this->assertCount(3, $mails);
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'recovery was approved')));
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'has been restored')));
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'Reset your')));
        $this->assertSame([self::EMAIL], array_values(array_unique(array_column($mails, 'to'))));

        $restored = collect($mails)->first(fn ($m) => str_contains($m['subject'], 'has been restored'));
        $this->assertStringContainsString('previous password no longer works', $restored['body']);
        $this->assertStringNotContainsString('You can sign in again', $restored['body']);
        $this->assertTrue($old->fresh()->isDeleted() === false);
    }

    public function test_the_restoration_email_tells_a_notary_about_fresh_approval(): void
    {
        $this->archived('notary');
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        $restored = collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'has been restored'));

        $this->assertStringContainsString('stays closed until an administrator approves it again', $restored['body']);
    }

    /** Module 3 (Decision A): a plain restore now locks the account the same way a recovery does. */
    public function test_a_plain_restore_also_requires_a_new_password(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();

        app(AccountDeletionService::class)->restore($admin, $old, self::REASON);

        $restored = collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'has been restored'));
        $this->assertStringContainsString('Your previous password no longer works', $restored['body']);
        $this->assertStringNotContainsString('You can sign in again', $restored['body']);
        $this->assertTrue($old->fresh()->mustResetPassword());
        $this->assertNotNull(collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'Reset your')));
    }

    public function test_every_notice_status_is_tracked(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        $this->approve($admin, $recovery);
        $recovery = $recovery->fresh();

        $this->assertSame('sent', $recovery->noticeStatus('requested'));
        $this->assertSame('sent', $recovery->noticeStatus('approved'));
        $this->assertSame('sent', $recovery->noticeStatus('reset'));
        $this->assertSame('sent', $recovery->deletion->noticeStatus('restore'));
        $this->assertNull($recovery->noticeStatus('alert'));
        $this->assertNull($recovery->noticeStatus('rejected'));

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertOk()->assertSee('Password reset link')->assertSee('Restoration');
    }

    public function test_the_replaced_account_shows_no_failed_email(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $this->approve($admin, $this->requestRecovery($new));

        $record = AccountDeletion::where('user_id', $new->id)->sole();

        $this->assertTrue($record->notice_suppressed);
        $this->assertSame('suppressed', $record->noticeStatus('deletion'));
        $this->assertFalse($record->canRetry('deletion'));
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertOk()->assertSee('Not needed: replaced by recovery');
    }

    // ---- Addresses

    public function test_no_message_ever_goes_to_a_placeholder(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->approve($admin, $this->requestRecovery($this->registered()));

        foreach ($this->sent() as $mail) {
            $this->assertStringNotContainsString('deleted.invalid', $mail['to']);
            $this->assertStringNotContainsString('deleted.invalid', (string) $mail['body']);
        }

        $this->assertFalse(MessageDelivery::where('recipient', 'like', '%deleted.invalid')->exists());
    }

    public function test_an_archived_account_gets_no_ordinary_mail(): void
    {
        $old = $this->archived();
        $new = $this->registered();
        $before = count($this->sent());

        // The address now belongs to the new account, which must not read the old one's mail.
        $old->notify(new PortalNotification('An old application moved', 'NR-1', route('home')));

        $this->assertCount($before, $this->sent());
        $this->assertSame(self::EMAIL, $new->email);
    }

    public function test_administrators_see_the_real_identity_of_an_archived_account(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $application = $this->application($old, ['status' => 'pending']);

        $this->actingAs($admin)->get(route('admin.users.show', $old))->assertOk()->assertSee(self::EMAIL)->assertSee(self::MOBILE)->assertDontSee('deleted.invalid');
        $this->actingAs($admin)->get(route('admin.requests.show', $application))->assertOk()->assertSee(self::EMAIL)->assertDontSee('deleted.invalid');
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertOk()->assertSee('Email and mobile released');
    }

    // ---- Condition 6: nothing is purged

    public function test_no_routine_removes_archived_accounts_or_their_identity(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $this->requestRecovery($this->registered());
        // Past every routine's cutoff, the longest being 365 days. A MariaDB TIMESTAMP ends in 2038.
        $this->travel(2)->years();

        $archivedDraft = ServiceDraft::create(['user_id' => $old->id, 'service_slug' => 'rent-agreement', 'details' => []]);
        $activeDraft = ServiceDraft::create(['user_id' => $admin->id, 'service_slug' => 'rent-agreement', 'details' => []]);
        DB::table('service_drafts')->update(['updated_at' => now()->subYear()]);

        foreach (['enotary:purge-expired --apply', 'enotary:clear-expired-codes', 'enotary:clear-stale-drafts'] as $command) {
            Artisan::call($command);
        }

        $this->assertTrue(ServiceDraft::whereKey($archivedDraft->id)->exists(), 'An archived account keeps its data.');
        $this->assertFalse(ServiceDraft::whereKey($activeDraft->id)->exists(), 'The routine still works for everyone else.');
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(1, AccountDeletion::count());
        $this->assertSame(self::EMAIL, AccountDeletion::sole()->email);
        $this->assertSame(1, AccountRecoveryRequest::count());
        $this->assertNotNull($admin->fresh());
    }

    // ---- Phase 5: identity conflicts

    /** A person holds the old mobile on another account, and asks to recover by email. */
    private function conflict(): array
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $holder = $this->person('public', ['email' => 'holder@example.test', 'mobile' => self::MOBILE, 'name' => 'Mobile Holder']);
        $new = $this->registered(self::EMAIL, '9000000050');

        return [$old, $admin, $holder, $this->requestRecovery($new), $new];
    }

    public function test_a_conflict_is_shown_and_never_resolved_without_confirmation(): void
    {
        [$old, $admin, $holder, $recovery] = $this->conflict();

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertOk()
            ->assertSee('Identity conflict.')->assertSee('Mobile Holder');

        $this->approve($admin, $recovery)->assertSessionHas('error');
        $this->approve($admin, $recovery, ['conflict_confirmed' => '1'])->assertSessionHas('error');
        $this->approve($admin, $recovery, ['conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($holder->fresh()->isDeleted());
        $this->assertSame(self::MOBILE, $holder->fresh()->mobile);
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    public function test_a_confirmed_conflict_switches_atomically_and_merges_nothing(): void
    {
        [$old, $admin, $holder, $recovery, $new] = $this->conflict();
        $holderApplication = $this->application($holder);
        $oldApplication = $this->application($old);
        $before = count($this->sent());

        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('status');

        $this->assertFalse($old->fresh()->isDeleted());
        $this->assertSame(self::MOBILE, $old->fresh()->mobile);
        $this->assertTrue($holder->fresh()->isDeleted());
        $this->assertTrue($new->fresh()->isDeleted());
        $this->assertSame($holder->id, $holderApplication->fresh()->user_id, 'Nothing moves between accounts.');
        $this->assertSame($old->id, $oldApplication->fresh()->user_id);

        $recovery = $recovery->fresh();
        $this->assertSame('Same person, confirmed by telephone.', $recovery->conflict_resolution);
        $this->assertSame($admin->id, $recovery->reviewed_by);
        $this->assertNotNull($recovery->reviewed_at);

        // The third party hears about it, at their own address.
        $this->assertContains('holder@example.test', array_column(array_slice($this->sent(), $before), 'to'));
        $this->assertSame(1, User::whereNull('deleted_at')->where('mobile', self::MOBILE)->count());
    }

    public function test_a_failure_during_approval_changes_nothing(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);
        $before = count($this->sent());

        AccountRecoveryRequest::updating(function () {
            throw new RuntimeException('Simulated failure at the last step.');
        });

        $this->withoutExceptionHandling();

        try {
            app(AccountRecoveryService::class)->approve($admin, $recovery, false, null);
            $this->fail('The failure should reach the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure at the last step.', $e->getMessage());
        }

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertStringEndsWith('@deleted.invalid', $old->fresh()->email);
        $this->assertFalse($new->fresh()->isDeleted());
        $this->assertSame(self::EMAIL, $new->fresh()->email);
        $this->assertSame(1, AccountDeletion::count());
        $this->assertNull(AccountDeletion::sole()->restored_at);
        $this->assertTrue($recovery->fresh()->isOpen());
        $this->assertNull($old->fresh()->password_reset_required_at);
        $this->assertCount($before, $this->sent(), 'Nothing is emailed for a change that did not happen.');
    }

    public function test_a_holder_with_open_applications_blocks_approval(): void
    {
        [$old, $admin, $holder, $recovery] = $this->conflict();
        $this->application($holder, ['status' => 'pending']);

        $this->actingAs($admin)->get(route('admin.recovery.show', $recovery))->assertOk()->assertSee('Cannot be resolved here.')->assertDontSee('Approve recovery');
        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($holder->fresh()->isDeleted());
    }

    public function test_an_admin_holding_the_identity_blocks_approval(): void
    {
        [$old, $admin, $holder, $recovery] = $this->conflict();
        $holder->forceFill(['role' => 'admin'])->save();

        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($holder->fresh()->isDeleted());
    }

    public function test_a_holder_with_an_approved_application_awaiting_completion_blocks_approval(): void
    {
        [$old, $admin, $holder, $recovery] = $this->conflict();
        $this->application($holder, ['status' => NotaryRequest::STATUS_APPROVED]);

        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Same person, confirmed by telephone.'])->assertSessionHas('error');

        $this->assertFalse($holder->fresh()->isDeleted());
    }

    public function test_a_requester_whose_proven_address_changed_cannot_be_approved(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        // The number still matches the old account, but it was not what this person proved.
        $new->fresh()->forceFill(['email' => 'moved@example.test'])->save();

        $this->approve($admin, $recovery)->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($new->fresh()->isDeleted());
    }

    public function test_a_database_failure_shows_no_sql_to_the_admin(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        AccountDeletion::updating(function () {
            throw new \Illuminate\Database\QueryException('sqlite', 'update users set password = ?', ['secret-hash'], new RuntimeException('Deadlock found'));
        });

        $response = $this->approve($admin, $recovery);

        $response->assertSessionHas('error', 'That could not be completed, and nothing was changed. Please try again.');
        $this->assertStringNotContainsString('secret-hash', (string) session('error'));
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    public function test_a_late_choice_cannot_release_a_request_already_made(): void
    {
        $this->archived();
        $new = $this->registered();
        $stale = $new->fresh();
        $stale->accountChoiceRoute();
        $recovery = $this->requestRecovery($new);

        // The other tab's "continue with a new account", which read the old state.
        app(AccountRecoveryService::class)->chooseNewAccount($stale);

        $this->assertSame(User::CHOICE_RECOVERY, $new->fresh()->previous_account_choice);
        $this->actingAs($new->fresh())->get(route('dashboard'))->assertRedirect(route('account.recovery.status'));
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    public function test_a_late_cancel_cannot_undo_an_administrators_decision(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $stale = $new->fresh();
        $recovery = $this->requestRecovery($new);
        $this->approve($admin, $recovery);

        app(AccountRecoveryService::class)->cancel($stale);

        $this->assertSame(AccountRecoveryRequest::APPROVED, $recovery->fresh()->status);
        $this->assertFalse($old->fresh()->isDeleted());
    }

    public function test_an_archived_notary_cannot_be_approved(): void
    {
        $old = $this->archived('notary');
        $admin = User::where('role', 'admin')->first();
        DB::table('users')->where('id', $old->id)->update(['notary_approved_at' => null]);

        $this->actingAs($admin)->post(route('admin.notaries.approve', $old))->assertSessionHas('error');

        $this->assertNull($old->fresh()->notary_approved_at);
    }

    public function test_a_requester_who_no_longer_holds_the_identity_cannot_be_approved(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        $new->fresh()->forceFill(['email' => 'moved@example.test', 'mobile' => '9000000060'])->save();

        $this->approve($admin, $recovery)->assertSessionHas('error');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($new->fresh()->isDeleted());
    }

    public function test_a_conflict_can_be_cancelled_and_the_new_account_kept(): void
    {
        [$old, $admin, $holder, $recovery, $new] = $this->conflict();

        $this->actingAs($admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'Cannot be resolved safely at this time.'])->assertSessionHas('status');

        $this->assertSame(AccountRecoveryRequest::CANCELLED, $recovery->fresh()->status);
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($holder->fresh()->isDeleted());
        $this->actingAs($new->fresh())->get(route('dashboard'))->assertOk();
        $this->assertStringContainsString('Cannot be resolved safely', collect($this->sent())->last()['body']);
    }

    public function test_a_requester_with_records_needs_confirmation_too(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);
        $this->application($new, ['status' => 'completed']);

        $this->approve($admin, $recovery)->assertSessionHas('error');
        $this->assertTrue($old->fresh()->isDeleted());

        $this->approve($admin, $recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Both accounts are this person.'])->assertSessionHas('status');
        $this->assertSame(1, NotaryRequest::where('user_id', $new->id)->count(), 'The new account keeps its own record.');
    }

    public function test_a_deleted_admin_is_refused_at_the_approval_step_too(): void
    {
        $target = $this->person('admin', ['email' => 'old.admin@example.test', 'mobile' => '9000000120']);
        $admin = $this->admin();
        app(AccountDeletionService::class)->delete($admin, $target, self::REASON);
        $deletion = AccountDeletion::sole();
        $person = $this->person('public', ['email' => 'someone@example.test', 'mobile' => '9000000121']);

        // A request that could only exist through a fault elsewhere. This is the last line.
        $recovery = AccountRecoveryRequest::create(['reference' => 'REC-FORGED', 'account_deletion_id' => $deletion->id, 'old_user_id' => $target->id, 'requester_id' => $person->id, 'applicant_name' => 'Forged', 'verified_email' => $person->email, 'verified_mobile' => $person->mobile, 'match_type' => 'email', 'email_proof' => 'emailed_code', 'mobile_proof' => 'none', 'environment' => 'production', 'open_key' => $deletion->id]);

        $this->approve($admin, $recovery)->assertSessionHas('error', 'An administrator account cannot be recovered here.');

        $this->assertTrue($target->fresh()->isDeleted());
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    // ---- Failures, duplicates and races

    public function test_a_person_who_chose_a_new_account_cannot_file_a_request(): void
    {
        $this->archived();
        $new = $this->registered();
        $this->actingAs($new)->post(route('account.previous.new'));

        $this->assertNull(app(AccountRecoveryService::class)->submit($new->fresh(), AccountDeletion::sole()->id));

        $this->assertSame(0, AccountRecoveryRequest::count());
    }

    public function test_a_cancel_that_loses_the_race_to_an_admin_changes_nothing(): void
    {
        $old = $this->archived();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);
        $changed = false;

        // The decision commits after the cancel has found the request and before it takes the lock.
        DB::listen(function ($query) use ($recovery, &$changed) {
            if (! $changed && preg_match('/^select .?id.? from .?account_recovery_requests/', $query->sql)) {
                $changed = true;
                DB::table('account_recovery_requests')->where('id', $recovery->id)->update(['status' => AccountRecoveryRequest::APPROVED, 'open_key' => null]);
            }
        });

        app(AccountRecoveryService::class)->cancel($new->fresh());

        $this->assertTrue($changed, 'The race must really have happened.');
        $this->assertSame(AccountRecoveryRequest::APPROVED, $recovery->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'account.recovery_cancelled')->exists());
        $this->assertSame(User::CHOICE_RECOVERY, $new->fresh()->previous_account_choice);
        $this->assertTrue($old->fresh()->isDeleted());
    }

    public function test_a_queue_outage_leaves_a_correct_account_and_offers_a_retry(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        config(['queue.default' => 'not-a-connection']);

        $this->approve($admin, $recovery)->assertSessionHas('status');

        $recovery = $recovery->fresh();
        $this->assertFalse($old->fresh()->isDeleted());
        $this->assertTrue($old->fresh()->mustResetPassword());
        $this->assertSame('not_queued', $recovery->noticeStatus('approved'));
        $this->assertSame('not_queued', $recovery->noticeStatus('reset'));
        $this->assertSame('not_queued', $recovery->deletion->noticeStatus('restore'));

        config(['queue.default' => 'sync']);
        $before = count($this->sent());

        $this->actingAs($admin)->post(route('admin.recovery.retry', [$recovery, 'approved']))->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.recovery.retry', [$recovery, 'reset']))->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$recovery->deletion, 'restore']))->assertSessionHas('status');

        $this->assertCount($before + 3, $this->sent());
        $this->assertStringContainsString('reset-password/', collect($this->sent())->first(fn ($m) => str_contains($m['subject'], 'Reset your'))['body']);
    }

    public function test_a_sent_notice_cannot_be_sent_twice(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        $this->approve($admin, $recovery);
        $before = count($this->sent());

        foreach (['requested', 'approved', 'reset'] as $notice) {
            $this->actingAs($admin)->post(route('admin.recovery.retry', [$recovery, $notice]))->assertSessionHas('error');
        }

        $this->assertCount($before, $this->sent());
    }

    public function test_an_unknown_notice_is_refused(): void
    {
        $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());

        $this->actingAs($admin)->post(route('admin.recovery.retry', [$recovery, 'password']))->assertSessionHasErrors('notice');
    }

    public function test_a_reset_link_is_not_resent_once_the_password_is_set(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $recovery = $this->requestRecovery($this->registered());
        config(['queue.default' => 'not-a-connection']);
        $this->approve($admin, $recovery);
        config(['queue.default' => 'sync']);
        $this->resetWith(self::EMAIL, $this->resetToken($old->fresh()), self::NEW_PASSWORD);

        $this->actingAs($admin)->post(route('admin.recovery.retry', [$recovery, 'reset']))->assertSessionHas('error');
    }

    public function test_two_people_cannot_hold_open_requests_for_one_account(): void
    {
        $this->archived();
        $first = $this->registered();
        $recovery = $this->requestRecovery($first);

        $second = $this->person('public', ['email' => 'second@example.test', 'mobile' => '9000000070']);
        DB::table('users')->where('id', $second->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
        // The real path: a genuine SMS match on the same record.
        AccountDeletion::sole()->update(['mobile' => '9000000070']);

        $result = app(AccountRecoveryService::class)->submit($second->fresh(), AccountDeletion::sole()->id);

        $this->assertNull($result);
        $this->assertSame(1, AccountRecoveryRequest::count());
        $this->assertTrue($recovery->fresh()->isOpen());
        $this->assertSame(User::CHOICE_PENDING, $second->fresh()->previous_account_choice);
    }

    public function test_submitting_twice_creates_one_request(): void
    {
        $this->archived();
        $new = $this->registered();
        $this->requestRecovery($new);

        $this->assertNull(app(AccountRecoveryService::class)->submit($new->fresh(), AccountDeletion::sole()->id));
        $this->assertSame(1, AccountRecoveryRequest::count());
    }

    public function test_an_identity_taken_during_approval_is_a_clean_refusal(): void
    {
        $old = $this->archived();
        $admin = User::where('role', 'admin')->first();
        $new = $this->registered();
        $recovery = $this->requestRecovery($new);

        // A registration commits after the check, before the identity returns.
        AccountDeletion::updating(function (AccountDeletion $record) {
            if ($record->isDirty('restored_at')) {
                throw new \Illuminate\Database\UniqueConstraintViolationException('sqlite', 'insert', [], new RuntimeException('duplicate'));
            }
        });

        $this->approve($admin, $recovery)->assertSessionHas('error', 'The identity was taken while approving, so nothing was changed. Review the request again.');

        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($new->fresh()->isDeleted());
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    public function test_deleting_and_restoring_stay_closed_until_the_migration_has_run(): void
    {
        $admin = $this->admin();
        $citizen = $this->person();
        $support = new ReflectionProperty(User::class, 'accountRecoverySupported');
        $support->setValue(null, false);

        $this->assertTrue(Gate::forUser($admin)->denies('delete', $citizen));
        $this->assertTrue(Gate::forUser($admin)->denies('restore', $citizen));
        $this->assertNull($citizen->accountChoiceRoute());

        $this->actingAs($admin)->get(route('admin.recovery.index'))->assertNotFound();
        $this->actingAs($admin)->get('/admin/account-recovery/1')->assertNotFound();

        $this->register('premigration@example.test', '9000000080')->assertOk();
        $this->assertNull(User::where('email', 'premigration@example.test')->sole()->previous_account_choice);
    }

    public function test_the_held_mobile_step_cannot_change_the_number(): void
    {
        $this->archived();
        $this->bridgeOn();
        $this->register()->assertOk();
        $user = User::where('email', self::EMAIL)->sole();
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_method' => User::METHOD_DEV_EMAIL, 'mobile_verification_status' => User::STATUS_SIMULATED, 'mobile_simulated_at' => now()])->save();
        $this->requestRecovery($user->fresh());

        $this->actingAs($user->fresh())->post(route('mobile.verification.number'), ['mobile' => '9000000090', 'current_password' => 'New-account-password1'])->assertRedirect(route('account.recovery.status'));

        $this->assertSame(self::MOBILE, $user->fresh()->mobile);
    }

    public function test_the_request_csrf_rule_applies_on_a_real_site(): void
    {
        $this->archived();
        $new = $this->registered();
        $this->app['env'] = 'local';

        $this->actingAs($new)->post(route('account.previous.new'))->assertStatus(419);

        $this->assertNotSame(User::CHOICE_NEW, $new->fresh()->previous_account_choice);
    }
}
