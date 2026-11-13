<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The local application database (enotary_local) has not run the six migrations below until the
 * owner applies them. Every page a signed-in person can reach must then leave the missing
 * columns and tables alone. On MariaDB a query that names one fails with "Unknown column" and
 * the page answers 500.
 *
 * SQLite cannot show that failure: it reads a double-quoted name that matches no column as a
 * string literal, so `"identity_released_at" is not null` is simply true. These tests therefore
 * record every statement a request runs and fail on any mention of what the schema lacks. The
 * real down() methods remove the columns and tables, so the schema is exactly that of a database
 * which has not run them. DatabaseMigrations, not RefreshDatabase, so nothing leaks onward.
 */
class PendingMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Newest first. 000023 and 000024 put triggers on users that stop SQLite dropping 000021's
     * user columns, so they come off before it.
     */
    private const PENDING = [
        '2026_11_04_000027_create_support_ticket_attachments_table',
        '2026_11_04_000026_add_triage_to_support_tickets',
        '2026_11_04_000025_create_email_replacements_table',
        '2026_11_03_000024_restrict_audit_actor_deletion',
        '2026_11_03_000023_add_was_admin_marker',
        '2026_11_05_000021_add_account_recovery',
    ];

    /** Names that exist only once those migrations have run. */
    private const MISSING = [
        'identity_released_at', 'notice_suppressed', 'previous_account_choice', 'password_reset_required_at',
        'retired_passwords', 'was_admin_at', 'account_recovery_requests', 'email_replacements', 'support_ticket_attachments',
    ];

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PENDING as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->down();
        }

        $this->forgetRecoverySupport();
        DB::listen(fn ($query) => $this->statements[] = $query->sql);
    }

    /** DatabaseMigrations rolls every migration back after the test, so put these six back first. */
    protected function tearDown(): void
    {
        foreach (array_reverse(self::PENDING) as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        $this->forgetRecoverySupport();

        parent::tearDown();
    }

    private function forgetRecoverySupport(): void
    {
        (new ReflectionProperty(User::class, 'accountRecoverySupported'))->setValue(null, null);
    }

    /** A Citizen who has passed every verification step, like Demo Citizen #4. */
    private function verifiedCitizen(): User
    {
        $citizen = User::create([
            'name' => 'Premigration Citizen', 'email' => 'premigration.citizen@example.test', 'mobile' => '9000000091',
            'password' => Hash::make('demo-pass-123'), 'role' => 'public',
            'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ]);
        $this->statements = [];

        return $citizen;
    }

    private function assertNoQueryOnMissingSchema(): void
    {
        foreach ($this->statements as $sql) {
            // The guards themselves (Schema::hasTable / hasColumn) ask the catalogue by name.
            if (preg_match('/sqlite_master|information_schema|pragma_|PRAGMA /i', $sql)) {
                continue;
            }

            foreach (self::MISSING as $name) {
                $this->assertStringNotContainsString($name, $sql, "A query names {$name}, which this database does not have yet (MariaDB answers 500): {$sql}");
            }
        }
    }

    public function test_the_schema_really_lacks_the_pending_migrations(): void
    {
        $this->assertFalse(User::accountRecoverySupported());
        $this->assertFalse(Schema::hasColumn('account_deletions', 'identity_released_at'));
        $this->assertFalse(Schema::hasColumn('account_deletions', 'notice_suppressed'));
        $this->assertFalse(Schema::hasColumn('users', 'previous_account_choice'));
        $this->assertFalse(Schema::hasTable('email_replacements'));
        $this->assertFalse(Schema::hasTable('support_ticket_attachments'));
    }

    public function test_the_recovery_code_page_redirects_without_touching_the_missing_columns(): void
    {
        $citizen = $this->verifiedCitizen();

        $this->actingAs($citizen)->get(route('account.previous.code'))->assertRedirect(route('dashboard'));

        $this->assertNoQueryOnMissingSchema();
    }

    public function test_asking_for_a_recovery_code_redirects_sends_nothing_and_leaves_the_missing_columns_alone(): void
    {
        Notification::fake();
        $citizen = $this->verifiedCitizen();

        $this->actingAs($citizen)->post(route('account.previous.send'))->assertRedirect(route('dashboard'));

        Notification::assertNothingSent();
        $this->assertNoQueryOnMissingSchema();
    }

    public function test_every_other_previous_account_page_redirects_without_touching_the_missing_columns(): void
    {
        $citizen = $this->verifiedCitizen();

        $this->actingAs($citizen)->get(route('account.previous'))->assertRedirect(route('dashboard'));
        $this->actingAs($citizen)->post(route('account.previous.new'))->assertRedirect(route('dashboard'));
        $this->actingAs($citizen)->post(route('account.previous.confirm'), ['otp' => '123456'])->assertRedirect(route('dashboard'));
        $this->actingAs($citizen)->get(route('account.recovery.status'))->assertRedirect(route('dashboard'));

        $this->assertNoQueryOnMissingSchema();
    }

    /** Audit B-6: route model binding queried the missing email_replacements table (500). */
    public function test_cancelling_an_email_replacement_is_not_found_instead_of_failing(): void
    {
        $admin = User::create([
            'name' => 'Premigration Admin', 'email' => 'premigration.admin@example.test', 'mobile' => '9000000092',
            'password' => Hash::make('demo-pass-123'), 'role' => 'admin', 'email_verified_at' => now(),
        ]);
        $this->statements = [];

        $this->actingAs($admin)->post('/admin/email-replacements/1/cancel', ['reason' => 'Synthetic cancel for testing.'])->assertNotFound();

        $this->assertNoQueryOnMissingSchema();
    }

    public function test_the_citizen_dashboard_works_without_touching_the_missing_columns(): void
    {
        $citizen = $this->verifiedCitizen();

        $this->actingAs($citizen)->get(route('dashboard'))->assertOk();

        $this->assertNoQueryOnMissingSchema();
    }
}
