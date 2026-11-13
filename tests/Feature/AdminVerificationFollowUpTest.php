<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TemporaryAccessGrant;
use App\Models\User;
use App\Services\ManualVerificationService;
use App\Services\VerificationLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Follow-ups to Admin manual verification and temporary access: the privacy export, the Admin user
 * details labels, the audit of expiry and of a grant ended by deletion or deactivation, and the
 * state left after an account is restored. Nothing is ever sent: notifications are faked, and no
 * HTTP request (so no SMS provider) is made.
 */
class AdminVerificationFollowUpTest extends TestCase
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
        config(['mail.support_address' => 'help@enotary.example.test']);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Admin One', 'email' => 'admin.one@example.test', 'password' => Hash::make(self::ADMIN_PASSWORD)])->fresh();
    }

    private function citizen(array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => 'public', 'name' => 'Follow Up Citizen', 'email' => 'follow.citizen@example.test', 'mobile' => '9876500121',
            'email_verified_at' => null, 'mobile_verified_at' => null, 'password' => Hash::make('citizen-password-1'),
        ])->fresh();
    }

    private function service(): ManualVerificationService
    {
        return app(ManualVerificationService::class);
    }

    // ---- B1: privacy export --------------------------------------------------------------------

    public function test_the_privacy_export_includes_verification_history_and_access_without_secrets(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $this->service()->verify($admin, $citizen, 'email', self::REASON, 'video_call_id');
        app(VerificationLedger::class)->record($citizen->fresh(), VerificationLedger::PURPOSE_MOBILE, User::METHOD_EMAIL_FALLBACK, 'email', User::SMS_NOT_SENT);
        $grant = $this->service()->grant($admin, $citizen->fresh(), 2, self::REASON, 'phone_call');
        $hash = DB::table('verification_events')->value('target_hash');

        // The export is refused during temporary access, so it is read once the steps are done.
        $citizen = $citizen->fresh();
        $this->service()->verify($admin, $citizen, 'mobile', self::REASON, 'phone_call');

        $response = $this->actingAs($citizen->fresh())->get(route('privacy-center.export'))->assertOk();
        $raw = $response->getContent();
        $export = json_decode($raw, true);

        $history = $export['verification_history'];
        $this->assertCount(3, $history);
        $this->assertSame(['email', User::METHOD_ADMIN_MANUAL, 'admin'], [$history[0]['purpose'], $history[0]['method'], $history[0]['delivered_via']]);
        $this->assertSame('administrator', $history[0]['verified_by']);
        $this->assertSame('video_call_id', $history[0]['ownership_confirmed_by']);
        $this->assertSame('pending -> admin_manual', $history[0]['status_change']);
        $this->assertSame(User::METHOD_EMAIL_FALLBACK, $history[1]['method']);
        $this->assertArrayNotHasKey('verified_by', $history[1], 'an account holder\'s own verification names no administrator');
        $this->assertStringNotContainsString('follow.citizen@example.test', $history[0]['address_or_number']);
        $this->assertStringNotContainsString('9876500121', $history[2]['address_or_number']);

        $this->assertSame([['granted_at', 'expires_at', 'revoked_at', 'status']], array_map('array_keys', $export['temporary_access']));
        $this->assertSame($grant->expires_at->toIso8601String(), $export['temporary_access'][0]['expires_at']);

        // No hash, no actor, no Admin note anywhere in the file.
        $this->assertStringNotContainsString($hash, $raw);
        $this->assertStringNotContainsString(self::REASON, $raw);
        $this->assertStringNotContainsString('Admin One', $raw);
        $this->assertStringNotContainsString('admin.one@example.test', $raw);
        $this->assertStringNotContainsString('target_hash', $raw);
        $this->assertStringNotContainsString('actor_id', $raw);
    }

    // ---- B2: Admin user details labels ---------------------------------------------------------

    public function test_admin_user_details_name_the_email_method(): void
    {
        $admin = $this->admin();
        $byLink = $this->citizen(['email' => 'link@example.test', 'mobile' => '9876500122', 'email_verified_at' => now()]);
        $byCode = $this->citizen(['email' => 'code@example.test', 'mobile' => '9876500123', 'email_verified_at' => now()]);
        $byCode->forceFill(['email_verification_method' => User::METHOD_EMAIL_CODE])->save();
        $manual = $this->citizen(['email' => 'manual@example.test', 'mobile' => '9876500124']);
        $this->service()->verify($admin, $manual, 'email', self::REASON, 'phone_call');
        $pending = $this->citizen(['email' => 'pending@example.test', 'mobile' => '9876500125']);

        $this->actingAs($admin)->get(route('admin.users.show', $byLink))->assertOk()->assertSee('Verified via Email Link');
        $this->actingAs($admin)->get(route('admin.users.show', $byCode))->assertOk()->assertSee('Verified via Email OTP')->assertDontSee('Verified via Email Link');
        $this->actingAs($admin)->get(route('admin.users.show', $manual))->assertOk()->assertSee('Verified Manually by Admin')->assertDontSee('Verified via Email Link');
        $this->actingAs($admin)->get(route('admin.users.show', $pending))->assertOk()->assertSee('Not verified')->assertDontSee('Verified via Email');
    }

    // ---- B3: audit of expiry and of a grant ended with the account ------------------------------

    public function test_expiry_is_audited_once_by_the_command_and_changes_no_grant(): void
    {
        $admin = $this->admin();
        $expiring = $this->citizen();
        $revoked = $this->citizen(['email' => 'revoked@example.test', 'mobile' => '9876500126']);
        $this->service()->grant($admin, $expiring, 1, self::REASON, 'phone_call');
        $grant = $this->service()->grant($admin, $revoked, 1, self::REASON, 'phone_call');
        $this->service()->revoke($admin, $revoked, $grant, 'Ended early for the test.');
        auth()->logout();

        $this->artisan('enotary:record-access-expiry')->expectsOutputToContain('recorded: 0.')->assertSuccessful();

        $this->travel(61)->minutes();
        $before = DB::table('temporary_access_grants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->artisan('enotary:record-access-expiry')->expectsOutputToContain('recorded: 1.')->assertSuccessful();
        $this->artisan('enotary:record-access-expiry')->expectsOutputToContain('recorded: 0.')->assertSuccessful();

        $entry = AuditLog::where('action', ManualVerificationService::ACCESS_EXPIRED)->sole();
        $first = TemporaryAccessGrant::where('user_id', $expiring->id)->sole();
        $this->assertNull($entry->user_id, 'a system entry has no actor');
        $this->assertSame(['TemporaryAccessGrant', $first->id], [$entry->model_type, $entry->model_id]);
        $this->assertSame($expiring->id, $entry->meta['user_id']);
        $this->assertSame($first->expires_at->toIso8601String(), $entry->meta['expired_at']);
        $this->assertSame($before, DB::table('temporary_access_grants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'the command changes no grant');

        // An explicit revocation stays its own entry, and is never also recorded as an expiry.
        $this->assertSame(1, AuditLog::where('action', ManualVerificationService::ACCESS_REVOKED)->count());
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'enotary:record-access-expiry'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_deleting_an_account_ends_its_grant_and_a_restore_does_not_revive_it(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $grant = $this->service()->grant($admin, $citizen, 24, self::REASON, 'phone_call');

        $this->actingAs($admin)->delete(route('admin.users.delete', $citizen), ['reason' => 'Requested by the account holder.', 'confirm' => '1'])->assertRedirect();

        $grant->refresh();
        $this->assertSame(TemporaryAccessGrant::STATUS_REVOKED, $grant->status());
        $this->assertSame($admin->id, $grant->revoked_by);
        $this->assertSame(ManualVerificationService::CAUSE_ACCOUNT_DELETED, $grant->revoke_reason);
        $entry = AuditLog::where('action', ManualVerificationService::ACCESS_REVOKED)->sole();
        $this->assertSame(['grant_id' => $grant->id, 'cause' => ManualVerificationService::CAUSE_ACCOUNT_DELETED], array_intersect_key($entry->meta, ['grant_id' => 0, 'cause' => 0]));

        $this->actingAs($admin)->post(route('admin.users.restore', $citizen), ['reason' => 'Restored after the holder asked.'])->assertRedirect();

        $restored = User::find($citizen->id);
        $restored->forceFill(['password_reset_required_at' => null, 'password' => Hash::make('a-new-password-1')])->save();
        $this->assertFalse($restored->fresh()->isDeleted());
        $this->assertNull($restored->fresh()->activeTemporaryAccess());
        $this->assertFalse($restored->fresh()->usingTemporaryAccess());
    }

    public function test_deactivating_an_account_ends_its_grant(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen();
        $grant = $this->service()->grant($admin, $citizen, 24, self::REASON, 'phone_call');

        $this->actingAs($admin)->delete(route('admin.users.destroy', $citizen), ['reason' => 'Suspicious activity.'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.users.reactivate', $citizen))->assertRedirect();

        $grant->refresh();
        $this->assertSame(TemporaryAccessGrant::STATUS_REVOKED, $grant->status());
        $this->assertSame(ManualVerificationService::CAUSE_ACCOUNT_DEACTIVATED, $grant->revoke_reason);
        $this->assertNull($citizen->fresh()->deactivated_at);
        $this->assertFalse($citizen->fresh()->usingTemporaryAccess());
    }

    // ---- B4: state after a restore -------------------------------------------------------------

    public function test_a_restore_clears_manual_and_fallback_mobile_state_but_keeps_history(): void
    {
        $admin = $this->admin();
        $citizen = $this->citizen(['email_verified_at' => now()]);
        $this->service()->verify($admin, $citizen, 'mobile', self::REASON, 'phone_call');
        $citizen->refresh()->forceFill(['mobile_fallback_verified_at' => now(), 'mobile_fallback_number' => $citizen->mobile, 'mobile_sms_status' => User::SMS_NOT_SENT])->save();
        $this->assertTrue($citizen->fresh()->mobileManualVerified());

        $this->actingAs($admin)->delete(route('admin.users.delete', $citizen), ['reason' => 'Requested by the account holder.', 'confirm' => '1'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.users.restore', $citizen), ['reason' => 'Restored after the holder asked.'])->assertRedirect();

        $restored = User::find($citizen->id);
        $this->assertFalse($restored->isDeleted());
        foreach (['mobile_verified_at', 'mobile_verification_method', 'mobile_verification_status', 'mobile_manual_verified_at', 'mobile_manual_number',
            'mobile_fallback_verified_at', 'mobile_fallback_number', 'mobile_sms_status'] as $column) {
            $this->assertNull($restored->{$column}, "{$column} must be cleared by a restore");
        }
        $this->assertFalse($restored->mobileManualVerified());
        $this->assertTrue($restored->needsMobileVerification());
        $this->assertSame(1, DB::table('verification_events')->where('user_id', $citizen->id)->where('method', User::METHOD_ADMIN_MANUAL)->count(), 'history stays');
    }
}
