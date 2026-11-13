<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TemporaryAccessGrant;
use App\Models\User;
use App\Services\ManualVerificationService;
use App\Services\VerificationLedger;
use App\Support\VerificationStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * Admin > User Verification Management: manual email and mobile verification, and time-limited
 * login access, for Citizens and Notaries. Every test ends by proving nothing was sent: no email,
 * no notification, no HTTP request (so no SMS provider).
 */
class AdminManualVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'admin-own-password-1';

    private const REASON = 'Called the registered number and confirmed identity with Aadhaar on video.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();

        parent::tearDown();
    }

    private function admin(array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => 'admin', 'name' => 'Admin One', 'email' => 'admin.one@example.test', 'password' => Hash::make(self::ADMIN_PASSWORD),
        ])->fresh();
    }

    private function citizen(array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => 'public', 'email' => 'citizen.manual@example.test', 'mobile' => '9876500101',
            'email_verified_at' => null, 'mobile_verified_at' => null, 'password' => Hash::make('citizen-password-1'),
        ])->fresh();
    }

    private function notary(bool $approved, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => 'notary', 'email' => 'notary.manual@example.test', 'mobile' => '9876500202',
            'email_verified_at' => null, 'mobile_verified_at' => null, 'notary_approved_at' => $approved ? now() : null,
        ])->fresh();
    }

    private function confirmation(array $overrides = []): array
    {
        return array_merge(['reason' => self::REASON, 'evidence_method' => 'video_call_id', 'confirm' => '1', 'admin_password' => self::ADMIN_PASSWORD], $overrides);
    }

    private function verify(User $admin, User $user, string $purpose, array $overrides = [])
    {
        return $this->actingAs($admin)->post(route('admin.verification.verify', $user), $this->confirmation(array_merge(['purpose' => $purpose], $overrides)));
    }

    private function grant(User $admin, User $user, int $hours = 2, array $overrides = [])
    {
        return $this->actingAs($admin)->post(route('admin.verification.access.grant', $user), $this->confirmation(['hours' => $hours] + $overrides));
    }

    private function events(User $user)
    {
        return DB::table('verification_events')->where('user_id', $user->id)->orderBy('id')->get();
    }

    // ---- Manual verification -------------------------------------------------------------------

    public function test_admin_manually_verifies_an_email_address(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();

        $this->verify($admin, $citizen, 'email')->assertRedirect(route('admin.verification.show', $citizen))->assertSessionHas('success');

        $citizen->refresh();
        $this->assertNotNull($citizen->email_verified_at);
        $this->assertSame(User::METHOD_ADMIN_MANUAL, $citizen->email_verification_method);
        $this->assertTrue($citizen->hasVerifiedEmail());
        $this->assertTrue($citizen->emailManuallyVerified());
        $this->assertSame('Verified Manually by Admin', VerificationStatus::for($citizen)['email']);
        $this->assertSame(['Verified Manually by Admin', 'en-badge-assigned'], VerificationStatus::badges($citizen)['email']);

        $event = $this->events($citizen)->sole();
        $this->assertSame('email', $event->purpose);
        $this->assertSame(User::METHOD_ADMIN_MANUAL, $event->method);
        $this->assertSame('admin', $event->delivered_via);
        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame(self::REASON, $event->reason);
        $this->assertSame('video_call_id', $event->evidence_method);
        $this->assertSame('pending', $event->previous_status);
        $this->assertSame(User::METHOD_ADMIN_MANUAL, $event->new_status);
        $this->assertStringNotContainsString('citizen.manual@example.test', $event->target_masked);

        $audit = AuditLog::where('action', ManualVerificationService::EMAIL_VERIFIED)->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($citizen->id, $audit->model_id);
        $this->assertSame('User', $audit->model_type);
        $this->assertSame(['purpose', 'method', 'evidence_method', 'reason', 'previous_status', 'new_status', 'target'], array_keys($audit->meta));
        $this->assertStringNotContainsString('citizen.manual@example.test', json_encode($audit->meta));
    }

    public function test_admin_manually_verifies_a_mobile_number_without_it_counting_as_sms(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen(['email_verified_at' => now()]);
        $this->assertTrue($citizen->needsMobileVerification());

        $this->verify($admin, $citizen, 'mobile')->assertRedirect()->assertSessionHas('success');

        $citizen->refresh();
        $this->assertNull($citizen->mobile_verified_at, 'A manual check must never set the SMS column.');
        $this->assertFalse($citizen->hasVerifiedMobile());
        $this->assertFalse($citizen->canRecoverByMobile(), 'A manual check must never unlock SMS password reset.');
        $this->assertTrue($citizen->mobileManualVerified());
        $this->assertFalse($citizen->needsMobileVerification());
        $this->assertSame(User::METHOD_ADMIN_MANUAL, $citizen->mobile_verification_method);
        $this->assertSame(User::STATUS_ADMIN_VERIFIED, $citizen->mobile_verification_status);
        $this->assertSame(User::SMS_NOT_SENT, $citizen->mobile_sms_status);
        $this->assertSame('9876500101', $citizen->mobile_manual_number);
        $this->assertFalse(VerificationStatus::productionVerified($citizen));
        $this->assertSame('Verified Manually by Admin', VerificationStatus::for($citizen)['mobile']);
        $this->assertSame('Verified with Admin assistance', VerificationStatus::for($citizen)['account']);
        $this->assertSame('Verified Manually by Admin', VerificationStatus::badges($citizen)['mobile'][0]);

        $event = $this->events($citizen)->sole();
        $this->assertSame(['mobile', User::METHOD_ADMIN_MANUAL, 'admin', User::SMS_NOT_SENT], [$event->purpose, $event->method, $event->delivered_via, $event->sms_status]);
        $this->assertStringNotContainsString('9876500101', $event->target_masked);
        $this->assertSame(1, AuditLog::where('action', ManualVerificationService::MOBILE_VERIFIED)->count());

        // The citizen area opens, without temporary access.
        $this->actingAs($citizen)->get(route('dashboard'))->assertOk();
    }

    public function test_email_and_mobile_verification_stay_independent(): void
    {
        $admin = $this->admin();
        $first = $this->citizen();
        $second = $this->citizen(['email' => 'second.citizen@example.test', 'mobile' => '9876500303']);

        $this->verify($admin, $first, 'email');
        $first->refresh();
        $this->assertTrue($first->hasVerifiedEmail());
        $this->assertFalse($first->mobileManualVerified());
        $this->assertTrue($first->needsMobileVerification());
        $this->assertNull($first->mobile_verification_method);

        $this->verify($admin, $second, 'mobile');
        $second->refresh();
        $this->assertTrue($second->mobileManualVerified());
        $this->assertNull($second->email_verified_at);
        $this->assertFalse($second->hasVerifiedEmail());
        $this->assertNull($second->email_verification_method);

        $this->assertSame(['email'], $this->events($first)->pluck('purpose')->all());
        $this->assertSame(['mobile'], $this->events($second)->pluck('purpose')->all());
    }

    public function test_changing_the_number_ends_a_manual_mobile_check_but_keeps_its_history(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen(['email_verified_at' => now()]);
        $this->verify($admin, $citizen, 'mobile');

        $citizen->refresh()->forceFill(['mobile' => '9876500999'])->save();

        $citizen->refresh();
        $this->assertFalse($citizen->mobileManualVerified());
        $this->assertNull($citizen->mobile_manual_number);
        $this->assertTrue($citizen->needsMobileVerification());
        $this->assertCount(1, $this->events($citizen));
    }

    public function test_an_already_verified_purpose_cannot_be_verified_again(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen(['email_verified_at' => now(), 'mobile_verified_at' => now()]);

        $this->verify($admin, $citizen, 'email')->assertSessionHas('error', 'This email address is already verified.');
        $this->verify($admin, $citizen, 'mobile')->assertSessionHas('error', 'This mobile number is already verified by SMS.');

        $this->assertCount(0, $this->events($citizen));
        $this->assertSame(0, AuditLog::where('action', 'like', 'admin.verification.%')->count());
    }

    // ---- Authorization -------------------------------------------------------------------------

    public function test_non_admins_cannot_verify_or_grant_access(): void
    {
        $target = $this->citizen();
        $otherCitizen = $this->citizen(['email' => 'other@example.test', 'mobile' => '9876500404', 'email_verified_at' => now(), 'mobile_verified_at' => now()]);
        $notary = $this->notary(true, ['email_verified_at' => now()]);

        foreach ([$otherCitizen, $notary] as $actor) {
            $this->actingAs($actor)->post(route('admin.verification.verify', $target), $this->confirmation(['purpose' => 'email', 'admin_password' => 'password']))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.verification.access.grant', $target), $this->confirmation(['hours' => 2, 'admin_password' => 'password']))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.verification.index'))->assertForbidden();
        }

        auth()->logout();
        $this->post(route('admin.verification.verify', $target), $this->confirmation(['purpose' => 'email']))->assertRedirect(route('login'));

        $this->assertNull($target->fresh()->email_verified_at);
        $this->assertCount(0, $this->events($target));
        $this->assertSame(0, TemporaryAccessGrant::count());
    }

    public function test_a_deactivated_admin_is_refused(): void
    {
        $admin = $this->admin(['deactivated_at' => now()]);
        $citizen = $this->citizen();

        // EnsureAccountActive ends the session first; the policy and the service refuse as well.
        $this->verify($admin, $citizen, 'email')->assertRedirect(route('login'));
        $this->assertSame('Only an active administrator can manage verification.', app(ManualVerificationService::class)->refusal($admin, $citizen));
        $this->assertNull($citizen->fresh()->email_verified_at);
    }

    public function test_an_admin_cannot_act_on_their_own_or_another_admin_or_a_deleted_account(): void
    {
        $admin = $this->admin(['email_verified_at' => null]);
        $otherAdmin = $this->admin(['email' => 'admin.two@example.test', 'email_verified_at' => null]);
        $deleted = $this->citizen();
        $deleted->forceFill(['deleted_at' => now()])->save();
        $deactivated = $this->citizen(['email' => 'off@example.test', 'mobile' => '9876500505']);
        $deactivated->forceFill(['deactivated_at' => now()])->save();

        $this->verify($admin, $admin, 'email')->assertForbidden();
        $this->verify($admin, $otherAdmin, 'email')->assertForbidden();
        $this->verify($admin, $deleted, 'email')->assertForbidden();
        $this->verify($admin, $deactivated, 'email')->assertForbidden();
        $this->grant($admin, $admin)->assertForbidden();
        $this->grant($admin, $otherAdmin)->assertForbidden();
        $this->grant($admin, $deleted)->assertForbidden();

        // The service refuses too, even when called directly.
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        try {
            app(ManualVerificationService::class)->verify($admin, $admin, 'email', self::REASON, 'phone_call');
        } finally {
            $this->assertNull($admin->fresh()->email_verified_at);
            $this->assertNull($otherAdmin->fresh()->email_verified_at);
            $this->assertNull($deleted->fresh()->email_verified_at);
            $this->assertSame(0, DB::table('verification_events')->count());
            $this->assertSame(0, TemporaryAccessGrant::count());
        }
    }

    public function test_reason_evidence_tick_box_and_own_password_are_all_mandatory(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();

        $cases = [
            'reason' => ['reason' => ''],
            'reason ' => ['reason' => 'too short'],
            'evidence_method' => ['evidence_method' => ''],
            'evidence_method ' => ['evidence_method' => 'guessed'],
            'confirm' => ['confirm' => null],
            'admin_password' => ['admin_password' => ''],
            'admin_password ' => ['admin_password' => 'not-my-password'],
            'purpose' => ['purpose' => 'both'],
        ];

        foreach ($cases as $field => $override) {
            $this->verify($admin, $citizen, 'email', $override)->assertSessionHasErrors(trim($field));
            $this->assertFalse(session()->hasOldInput('admin_password'), 'The password must never be kept in the session.');
        }

        $this->grant($admin, $citizen, 2, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->grant($admin, $citizen, 2, ['admin_password' => 'wrong-password'])->assertSessionHasErrors('admin_password');
        $this->grant($admin, $citizen, 0)->assertSessionHasErrors('hours');
        $this->grant($admin, $citizen, 73)->assertSessionHasErrors('hours');

        $this->assertNull($citizen->fresh()->email_verified_at);
        $this->assertCount(0, $this->events($citizen));
        $this->assertSame(0, TemporaryAccessGrant::count());
        // Only the two wrong passwords are recorded (SEC-1: each failed password is audited).
        $this->assertSame(2, AuditLog::where('action', 'admin.reauth_failed')->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'admin.%')->where('action', '!=', 'admin.reauth_failed')->count());
    }

    // ---- History and immutability --------------------------------------------------------------

    public function test_the_admin_page_shows_masked_history_and_no_secret(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->verify($admin, $citizen, 'email');
        $this->verify($admin, $citizen, 'mobile');
        $hash = DB::table('verification_events')->value('target_hash');

        $page = $this->actingAs($admin)->get(route('admin.verification.show', $citizen))->assertOk()
            ->assertSee('Verified Manually by Admin')->assertSee('Admin One')->assertSee(self::REASON);

        $html = $page->getContent();
        $this->assertStringNotContainsString('citizen.manual@example.test', $html);
        $this->assertStringNotContainsString('9876500101', $html);
        $this->assertStringNotContainsString($hash, $html);
        $this->assertStringNotContainsString((string) $citizen->password, $html);

        $this->actingAs($admin)->get(route('admin.verification.index'))->assertOk()
            ->assertSee('Verified Manually by Admin')->assertDontSee('citizen.manual@example.test')->assertDontSee('9876500101');

        // Admin accounts are not listed and have no verification page.
        $this->actingAs($admin)->get(route('admin.verification.show', $admin))->assertNotFound();
    }

    public function test_verification_history_cannot_be_changed_or_deleted_on_sqlite(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->verify($admin, $citizen, 'email');
        $id = DB::table('verification_events')->value('id');

        foreach ([
            fn () => DB::table('verification_events')->where('id', $id)->update(['reason' => 'rewritten']),
            fn () => DB::table('verification_events')->where('id', $id)->update(['method' => User::METHOD_SMS]),
            fn () => DB::table('verification_events')->where('id', $id)->delete(),
            fn () => DB::table('users')->where('id', $citizen->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The database allowed a change to verification history.');
            } catch (QueryException) {
                // Refused by the trigger or by ON DELETE RESTRICT.
            }
        }

        $this->assertSame(self::REASON, DB::table('verification_events')->where('id', $id)->value('reason'));
        $this->assertSame(User::METHOD_ADMIN_MANUAL, DB::table('verification_events')->where('id', $id)->value('method'));

        // The audit entry stays append-only as before.
        $audit = AuditLog::where('action', ManualVerificationService::EMAIL_VERIFIED)->sole();
        $this->expectException(LogicException::class);
        $audit->update(['action' => 'changed']);
    }

    public function test_audit_entries_are_refused_by_the_database_too(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->verify($admin, $citizen, 'email');
        $id = AuditLog::where('action', ManualVerificationService::EMAIL_VERIFIED)->value('id');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $id)->delete();
    }

    // A failed history or audit write rolling the change back: AdminManualVerificationRollbackTest,
    // which runs on SQLite and MariaDB (CREATE TRIGGER commits on MariaDB, so it needs its own class).

    // ---- Temporary access ----------------------------------------------------------------------

    public function test_temporary_access_lets_a_citizen_in_with_restricted_routes_only(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();

        $this->actingAs($citizen)->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $this->grant($admin, $citizen)->assertRedirect()->assertSessionHas('success');
        $grant = TemporaryAccessGrant::sole();
        $this->assertSame($admin->id, $grant->granted_by);
        $this->assertSame(1, AuditLog::where('action', ManualVerificationService::ACCESS_GRANTED)->count());

        $citizen = $citizen->fresh();
        $this->assertTrue($citizen->usingTemporaryAccess());

        // Login lands on the dashboard instead of the verification page.
        auth()->logout();
        $this->postJson(route('login.submit'), ['mobile' => 'citizen.manual@example.test', 'password' => 'citizen-password-1'])
            ->assertOk()->assertJson(['redirect' => route('dashboard')]);

        // Allowed: pages that only display, Support, Privacy, the verification pages.
        $this->actingAs($citizen)->get(route('dashboard'))->assertOk()->assertSee('Temporary Access Active');
        $this->actingAs($citizen)->get(route('support.index'))->assertOk();
        $this->actingAs($citizen)->get(route('privacy-center.index'))->assertOk();
        $this->actingAs($citizen)->get(route('user.requests'))->assertOk();
        $this->actingAs($citizen)->get(route('verification.notice'))->assertOk();

        // Refused: uploads, applications, account changes, bookings, data export.
        $this->actingAs($citizen)->get(route('user.upload'))->assertRedirect(route('dashboard'))->assertSessionHas('error');
        $this->actingAs($citizen)->get(route('user.services.apply', 'affidavit'))->assertRedirect(route('dashboard'));
        $this->actingAs($citizen)->get(route('privacy-center.export'))->assertRedirect(route('dashboard'));
        $this->actingAs($citizen)->put(route('user.profile.update'), ['name' => 'Changed', 'email' => 'taken.over@example.test'])->assertForbidden();
        $this->actingAs($citizen)->post(route('user.mobile.change'), ['mobile' => '9876500777', 'password' => 'citizen-password-1'])->assertForbidden();
        $this->actingAs($citizen)->post(route('mobile.verification.number'), ['mobile' => '9876500777'])->assertForbidden();
        $this->actingAs($citizen)->post(route('appointments.store'), [])->assertForbidden();
        $this->actingAs($citizen)->post(route('user.upload.store'), [])->assertForbidden();
        $this->actingAs($citizen)->post(route('account.previous.new'))->assertForbidden();

        // Nothing was verified, and nothing changed on the account.
        $citizen->refresh();
        $this->assertSame('citizen.manual@example.test', $citizen->email);
        $this->assertSame('9876500101', $citizen->mobile);
        $this->assertNull($citizen->email_verified_at);
        $this->assertNull($citizen->mobile_verified_at);
        $this->assertNull($citizen->email_verification_method);
        $this->assertNull($citizen->mobile_verification_method);
        $this->assertFalse($citizen->hasVerifiedEmail());
        $this->assertTrue($citizen->needsMobileVerification());
        $this->assertSame('Pending', VerificationStatus::for($citizen)['email']);
        $this->assertSame('Pending', VerificationStatus::for($citizen)['mobile']);
        $this->assertSame(['Temporary Access Active', 'en-badge-correction'], VerificationStatus::badges($citizen)['access']);
        $this->assertCount(0, $this->events($citizen));
    }

    public function test_temporary_access_covers_a_pending_mobile_step_too(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen(['email_verified_at' => now()]);
        $this->actingAs($citizen)->get(route('dashboard'))->assertRedirect(route('verification.mobile.notice'));

        $this->grant($admin, $citizen);

        $this->actingAs($citizen->fresh())->get(route('dashboard'))->assertOk();
        $this->assertNull($citizen->fresh()->mobile_verified_at);
        $this->assertTrue($citizen->fresh()->needsMobileVerification());
    }

    public function test_temporary_access_expires_on_its_own(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->grant($admin, $citizen, 1);

        $this->actingAs($citizen->fresh())->get(route('dashboard'))->assertOk();

        $this->travel(59)->minutes();
        $this->actingAs($citizen->fresh())->get(route('dashboard'))->assertOk();

        // No job, no scheduler and no write: the clock alone ends it.
        $this->travel(2)->minutes();
        $this->actingAs($citizen->fresh())->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->assertFalse($citizen->fresh()->usingTemporaryAccess());
        $this->assertSame(TemporaryAccessGrant::STATUS_EXPIRED, TemporaryAccessGrant::sole()->status());
        $this->assertSame(['Temporary Access Expired', 'en-badge-rejected'], VerificationStatus::badges($citizen->fresh())['access']);

        // An instance that read the grant earlier in a request also stops honouring it.
        $this->travelBack();
        $held = $citizen->fresh();
        $this->assertTrue($held->usingTemporaryAccess());
        $this->travel(2)->hours();
        $this->assertFalse($held->usingTemporaryAccess());
    }

    public function test_revoking_temporary_access_ends_it_and_is_recorded_once(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->grant($admin, $citizen, 24);
        $grant = TemporaryAccessGrant::sole();

        $revoke = fn (array $overrides = []) => $this->actingAs($admin)->post(route('admin.verification.access.revoke', [$citizen, $grant]),
            $overrides + ['revoke_reason' => 'Verification completed by phone.', 'confirm' => '1', 'admin_password' => self::ADMIN_PASSWORD]);

        $revoke(['revoke_reason' => ''])->assertSessionHasErrors('revoke_reason');
        $revoke(['admin_password' => 'wrong-password'])->assertSessionHasErrors('admin_password');
        $revoke(['confirm' => null])->assertSessionHasErrors('confirm');
        $this->assertNull($grant->fresh()->revoked_at);

        $revoke()->assertRedirect(route('admin.verification.show', $citizen))->assertSessionHas('success');

        $grant->refresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($admin->id, $grant->revoked_by);
        $this->assertSame(TemporaryAccessGrant::STATUS_REVOKED, $grant->status());
        $this->assertSame(1, AuditLog::where('action', ManualVerificationService::ACCESS_REVOKED)->count());

        $this->actingAs($citizen->fresh())->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $revoke()->assertSessionHas('error', 'This temporary access was already revoked.');
        $this->assertSame(1, AuditLog::where('action', ManualVerificationService::ACCESS_REVOKED)->count());
    }

    public function test_grant_records_cannot_be_rewritten_or_deleted(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->grant($admin, $citizen, 2);
        $grant = TemporaryAccessGrant::sole();

        // The model refuses.
        foreach ([fn () => $grant->update(['expires_at' => now()->addYear()]), fn () => $grant->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('The model allowed a change to a grant.');
            } catch (LogicException) {
            }
        }

        // And so does the database.
        foreach ([
            fn () => DB::table('temporary_access_grants')->where('id', $grant->id)->update(['expires_at' => now()->addYear()]),
            fn () => DB::table('temporary_access_grants')->where('id', $grant->id)->update(['reason' => 'rewritten']),
            fn () => DB::table('temporary_access_grants')->where('id', $grant->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The database allowed a change to a grant.');
            } catch (QueryException) {
            }
        }

        // A revocation is the one change allowed, and only once.
        DB::table('temporary_access_grants')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by' => $admin->id, 'revoke_reason' => 'Ended early.']);
        $this->expectException(QueryException::class);
        DB::table('temporary_access_grants')->where('id', $grant->id)->update(['revoked_at' => now()->addHour(), 'revoked_by' => $admin->id]);
    }

    public function test_grants_are_refused_when_not_needed_or_already_active(): void
    {
        $admin = $this->admin();
        $verified = $this->citizen(['email' => 'done@example.test', 'mobile' => '9876500606', 'email_verified_at' => now(), 'mobile_verified_at' => now()]);
        $citizen = $this->citizen();

        $this->grant($admin, $verified)->assertSessionHas('error', 'This account has no pending verification step, so it needs no temporary access.');

        $this->grant($admin, $citizen)->assertSessionHas('success');
        $this->grant($admin, $citizen)->assertSessionHas('error', 'This account already has active temporary access. Revoke it first to change it.');

        $this->assertSame(1, TemporaryAccessGrant::count());
    }

    // ---- Notaries ------------------------------------------------------------------------------

    public function test_temporary_access_never_bypasses_notary_approval(): void
    {
        $admin = $this->admin();
        $notary = $this->notary(false);
        $this->actingAs($notary)->get(route('notary.pending'))->assertRedirect(route('verification.notice'));

        $this->grant($admin, $notary);
        $notary = $notary->fresh();

        $this->actingAs($notary)->get(route('notary.pending'))->assertOk();
        $this->actingAs($notary)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));
        $this->actingAs($notary)->post(route('notary.request.approve', 1))->assertForbidden();
        $this->actingAs($notary)->get(route('appointments.index'))->assertRedirect(route('notary.pending'));

        $notary->refresh();
        $this->assertNull($notary->notary_approved_at);
        $this->assertFalse($notary->isApprovedNotary());
        $this->assertNull($notary->email_verified_at);
    }

    public function test_an_approved_notary_with_temporary_access_cannot_make_decisions(): void
    {
        $admin = $this->admin();
        $notary = $this->notary(true);
        $this->grant($admin, $notary);
        $notary = $notary->fresh();

        $this->actingAs($notary)->get(route('notary.dashboard'))->assertOk();
        foreach (['notary.request.approve', 'notary.request.reject', 'notary.request.correct', 'notary.request.complete'] as $name) {
            $this->actingAs($notary)->post(route($name, 1))->assertForbidden();
        }
        $this->actingAs($notary)->post(route('notary.profile.password'), [])->assertForbidden();
    }

    public function test_manual_verification_never_approves_a_notary(): void
    {
        $admin = $this->admin();
        $pending = $this->notary(false);

        $this->verify($admin, $pending, 'email')->assertSessionHas('success');
        $this->verify($admin, $pending, 'mobile')->assertSessionHas('success');

        $pending->refresh();
        $this->assertTrue($pending->hasVerifiedEmail());
        $this->assertTrue($pending->mobileManualVerified());
        $this->assertNull($pending->notary_approved_at);
        $this->assertFalse($pending->isApprovedNotary());
        $this->assertSame(0, AuditLog::where('action', 'admin.notary_approved')->count());

        $this->actingAs($pending)->get(route('notary.dashboard'))->assertRedirect(route('notary.pending'));
        $this->actingAs($pending)->get(route('notary.pending'))->assertOk();
    }

    public function test_an_approved_notary_can_be_verified_manually(): void
    {
        $admin = $this->admin();
        $notary = $this->notary(true);

        $this->verify($admin, $notary, 'email')->assertSessionHas('success');

        $this->actingAs($notary->fresh())->get(route('notary.dashboard'))->assertOk();
    }

    // ---- Existing flows ------------------------------------------------------------------------

    public function test_existing_verification_methods_keep_their_own_labels(): void
    {
        $byCode = $this->citizen(['email' => 'code@example.test', 'mobile' => '9876500707', 'email_verified_at' => now(), 'mobile_verified_at' => now()]);
        $byCode->forceFill(['email_verification_method' => User::METHOD_EMAIL_CODE, 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        $byLink = $this->citizen(['email' => 'link@example.test', 'mobile' => '9876500808', 'email_verified_at' => now()]);
        $byLink->forceFill(['mobile_verification_method' => User::METHOD_EMAIL_FALLBACK, 'mobile_verification_status' => User::STATUS_FALLBACK_COMPLETED,
            'mobile_fallback_verified_at' => now(), 'mobile_fallback_number' => '9876500808'])->save();

        $this->assertSame('Verified (Email OTP)', VerificationStatus::for($byCode)['email']);
        $this->assertSame('Production verified', VerificationStatus::for($byCode)['account']);
        $this->assertSame(['Verified via Email OTP', 'en-badge-approved'], VerificationStatus::badges($byCode)['email']);
        $this->assertSame(['Verified via SMS', 'en-badge-approved'], VerificationStatus::badges($byCode)['mobile']);

        $this->assertSame(['Verified via Email Link', 'en-badge-approved'], VerificationStatus::badges($byLink->fresh())['email']);
        $this->assertSame(['Verified via Email Fallback', 'en-badge-assigned'], VerificationStatus::badges($byLink->fresh())['mobile']);
        $this->assertSame('Verified with email fallback', VerificationStatus::for($byLink->fresh())['account']);
        $this->assertNull(VerificationStatus::badges($byLink->fresh())['access']);

        // Manual verification is refused for a number already done by the email fallback.
        $this->verify($this->admin(), $byLink, 'mobile')->assertSessionHas('error', 'The mobile step is already complete through the email fallback.');
    }

    public function test_a_normal_ledger_record_is_unchanged_and_has_no_admin_fields(): void
    {
        $citizen = $this->citizen(['email_verified_at' => now()]);
        app(VerificationLedger::class)->record($citizen, VerificationLedger::PURPOSE_EMAIL, 'email_otp', 'email');

        $row = DB::table('verification_events')->sole();
        $this->assertSame(['email_otp', 'email'], [$row->method, $row->delivered_via]);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->reason);
        $this->assertNull($row->previous_status);
    }
}
