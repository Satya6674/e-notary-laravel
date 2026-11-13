<?php

namespace Tests\Feature;

use App\Models\TemporaryAccessGrant;
use App\Models\User;
use App\Services\VerificationLedger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

/**
 * enotary_local has run neither 000029 (verification_events, email fallback columns) nor 000030
 * (manual verification, temporary access). Until the owner applies them, every page must leave the
 * missing tables and columns alone, the Admin page must say the feature is unavailable, and no
 * write may be attempted. Same method as PendingMigrationSafetyTest: the real down() methods give
 * exactly that schema, and every statement a request runs is checked for a missing name.
 */
class AdminVerificationPendingSchemaTest extends TestCase
{
    use DatabaseMigrations;

    /** Newest first. */
    private const PENDING = [
        '2026_11_05_000030_add_admin_manual_verification',
        '2026_11_05_000029_add_mobile_email_fallback',
    ];

    private const MISSING = [
        'verification_events', 'temporary_access_grants', 'mobile_manual_verified_at', 'mobile_manual_number',
        'mobile_fallback_verified_at', 'mobile_fallback_number', 'mobile_sms_status',
    ];

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
        Notification::fake();

        foreach (self::PENDING as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->down();
        }

        $this->forgetSchemaState();
        DB::listen(fn ($query) => $this->statements[] = $query->sql);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Notification::assertNothingSent();

        foreach (array_reverse(self::PENDING) as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        $this->forgetSchemaState();

        parent::tearDown();
    }

    private function forgetSchemaState(): void
    {
        foreach (['mobileFallbackSupported', 'mobileManualSupported'] as $property) {
            (new ReflectionProperty(User::class, $property))->setValue(null, null);
        }

        VerificationLedger::forgetSchemaState();
        TemporaryAccessGrant::forgetSchemaState();
    }

    private function assertNoQueryOnMissingSchema(): void
    {
        foreach ($this->statements as $sql) {
            if (preg_match('/sqlite_master|information_schema|pragma_|PRAGMA /i', $sql)) {
                continue;
            }

            foreach (self::MISSING as $name) {
                $this->assertStringNotContainsString($name, $sql, "A query names {$name}, which enotary_local does not have yet: {$sql}");
            }
        }
    }

    private function make(string $role, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create($extra + [
            'name' => "Pending Schema {$n}", 'email' => "pending.schema.{$n}@example.test", 'mobile' => '90000001'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('pending-pass-123'), 'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ]);
    }

    public function test_the_schema_really_lacks_both_migrations(): void
    {
        $this->assertFalse(Schema::hasTable('verification_events'));
        $this->assertFalse(Schema::hasTable('temporary_access_grants'));
        $this->assertFalse(Schema::hasColumn('users', 'mobile_manual_verified_at'));
        $this->assertFalse(\App\Services\ManualVerificationService::supported());
    }

    public function test_citizen_and_notary_pages_leave_the_missing_schema_alone(): void
    {
        $citizen = $this->make('public');
        $unverified = $this->make('public', ['email_verified_at' => null, 'mobile_verified_at' => null]);
        $notary = $this->make('notary', ['notary_approved_at' => now()]);
        $this->statements = [];

        $this->actingAs($citizen)->get(route('dashboard'))->assertOk();
        $this->actingAs($citizen)->get(route('support.index'))->assertOk();
        $this->actingAs($unverified)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($notary)->get(route('notary.dashboard'))->assertOk();

        auth()->logout();
        $this->postJson(route('login.submit'), ['mobile' => $unverified->email, 'password' => 'pending-pass-123'])
            ->assertOk()->assertJson(['redirect' => route('verification.notice')]);

        $this->assertNoQueryOnMissingSchema();
    }

    public function test_the_privacy_export_and_the_expiry_command_leave_the_missing_schema_alone(): void
    {
        $citizen = $this->make('public');
        $this->statements = [];

        $export = json_decode($this->actingAs($citizen)->get(route('privacy-center.export'))->assertOk()->getContent(), true);
        $this->assertSame([], $export['verification_history']);
        $this->assertSame([], $export['temporary_access']);

        auth()->logout();
        $this->artisan('enotary:record-access-expiry')->expectsOutputToContain('recorded: 0.')->assertSuccessful();

        $this->assertNoQueryOnMissingSchema();
    }

    public function test_the_admin_pages_say_the_feature_is_unavailable_and_write_nothing(): void
    {
        $admin = $this->make('admin', ['password' => Hash::make('admin-pass-123')]);
        $citizen = $this->make('public', ['email_verified_at' => null]);
        $this->statements = [];

        $this->actingAs($admin)->get(route('admin.verification.index'))->assertOk()->assertSee('migrations 000029 and 000030');
        $this->actingAs($admin)->get(route('admin.verification.show', $citizen))->assertOk()
            ->assertSee('This feature needs a database update (migrations 000029 and 000030) that has not been applied yet.')
            ->assertDontSee('Verify manually');
        $this->actingAs($admin)->get(route('admin.users.show', $citizen))->assertOk();

        $this->actingAs($admin)->post(route('admin.verification.verify', $citizen), [
            'purpose' => 'email', 'reason' => 'Confirmed by phone call with photo identity.', 'evidence_method' => 'phone_call',
            'confirm' => '1', 'admin_password' => 'admin-pass-123',
        ])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.verification.access.grant', $citizen), [
            'hours' => 2, 'reason' => 'Confirmed by phone call with photo identity.', 'evidence_method' => 'phone_call',
            'confirm' => '1', 'admin_password' => 'admin-pass-123',
        ])->assertForbidden();

        $this->assertNull($citizen->fresh()->email_verified_at);
        $this->assertNoQueryOnMissingSchema();
    }
}
