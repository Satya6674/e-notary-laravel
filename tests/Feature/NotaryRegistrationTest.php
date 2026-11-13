<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\AccountDeletionService;
use App\Services\StaffNotifier;
use App\Support\Platform;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Module 1: Notary registration. Sign-up survives the steps after the account is saved,
 * a Notary awaiting approval gets a page of their own, and approval links and checks
 * point at the right place. Every account here is synthetic.
 */
class NotaryRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Demo-pass-123';

    private function user(string $role = 'public', array $extra = []): User
    {
        static $i = 100;
        $i++;

        $user = User::create([
            'name' => "Demo Person {$i}", 'email' => "demo{$i}@example.test", 'mobile' => '9400000'.$i,
            'password' => Hash::make(self::PASSWORD), 'role' => $role, 'email_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        // Columns such as deactivated_at are not fillable, so they are set directly.
        if ($extra) {
            $user->forceFill($extra)->save();
        }

        return $user;
    }

    private function signUp(array $override = [])
    {
        // The register limiter allows a few per minute; each call here tests the form, not the limit.
        Cache::flush();

        return $this->postJson('/custom-register', array_merge([
            'name' => 'New Notary', 'email' => 'new.notary@example.test', 'mobile' => '9555500001',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'notary', 'accept_terms' => '1',
        ], $override));
    }

    // ---- 1.1 and 1.2: the account is saved, so nothing after it may fail the sign-up

    public function test_notary_sign_up_works_before_the_deactivation_migration(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        Schema::table('users', fn ($table) => $table->dropColumn('deactivated_at'));
        Platform::forgetSchemaState();

        $this->signUp()->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, User::where('email', 'new.notary@example.test')->count());
        Notification::assertSentTo($admin, PortalNotification::class, fn ($n) => $n->title === 'New Notary account awaiting approval');
    }

    public function test_a_failing_admin_alert_does_not_fail_a_saved_sign_up(): void
    {
        $this->mock(StaffNotifier::class)->shouldReceive('accountRegistered')->once()->andThrow(new RuntimeException('alert down'));

        $this->signUp()->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, User::where('email', 'new.notary@example.test')->count());
        $this->assertAuthenticated();
    }

    public function test_a_failing_verification_email_is_reported_truthfully_and_the_account_stands(): void
    {
        Event::listen(Registered::class, fn () => throw new RuntimeException('queue down'));

        $this->signUp()->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('message', \App\Http\Controllers\Auth\RegisterController::CODE_NOT_SENT);

        $this->assertSame(1, User::where('email', 'new.notary@example.test')->count());
    }

    // ---- 1.3: a Notary awaiting approval gets a waiting page, and still no work

    public function test_a_pending_notary_lands_on_the_waiting_page_after_login(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null]);

        $this->postJson('/custom-login', ['mobile' => $pending->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('redirect', route('notary.pending'));
    }

    public function test_an_unverified_pending_notary_verifies_the_email_first(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null, 'email_verified_at' => null]);

        $this->postJson('/custom-login', ['mobile' => $pending->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('redirect', route('verification.notice'));
        $this->actingAs($pending)->get(route('notary.pending'))->assertRedirect(route('verification.notice'));
    }

    public function test_the_waiting_page_shows_only_the_persons_own_state(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null]);
        $citizen = $this->user();

        $this->actingAs($pending)->get(route('notary.pending'))->assertOk()
            ->assertSee('Awaiting Approval')
            ->assertSee('waiting for administrator approval')
            ->assertSee('not a government service')
            ->assertDontSee(route('notary.requests'), false)
            ->assertDontSee(route('reports.index'), false)
            ->assertDontSee($citizen->email);
    }

    public function test_every_notary_page_view_sends_a_pending_notary_to_the_waiting_page(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null]);

        foreach ([route('notary.dashboard'), route('notary.requests'), route('notary.profile'), route('support.index'), route('appointments.index'), route('reports.index')] as $url) {
            $this->actingAs($pending)->get($url)->assertRedirect(route('notary.pending'));
        }
    }

    public function test_a_pending_notary_is_still_refused_every_action(): void
    {
        $pending = $this->user('notary', ['notary_approved_at' => null]);

        $this->actingAs($pending)->post(route('notary.profile.password'), [
            'current_password' => self::PASSWORD, 'password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1',
        ])->assertForbidden();
        $this->actingAs($pending)->post(route('support.store'), ['subject' => 'Hello there', 'message' => 'A synthetic message.'])->assertForbidden();
        $this->actingAs($pending)->getJson(route('notary.dashboard'))->assertForbidden();

        $this->assertTrue(Hash::check(self::PASSWORD, $pending->fresh()->password));
    }

    public function test_only_a_pending_notary_can_open_the_waiting_page(): void
    {
        $this->actingAs($this->user('notary'))->get(route('notary.pending'))->assertRedirect(route('notary.dashboard'));
        $this->actingAs($this->user())->get(route('notary.pending'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('notary.pending'))->assertForbidden();
        auth()->logout();
        $this->get(route('notary.pending'))->assertRedirect(route('login'));
    }

    // ---- 1.4: the notices open the Notary's own pages

    public function test_approval_and_withdrawal_notices_link_to_pages_the_notary_can_open(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $notary = $this->user('notary', ['notary_approved_at' => null]);

        $this->actingAs($admin)->post(route('admin.notaries.approve', $notary))->assertRedirect();
        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->url === route('notary.dashboard'));
        $this->actingAs($notary->fresh())->get(route('notary.dashboard'))->assertOk();

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Approval withdrawn for this test.'])->assertRedirect();
        Notification::assertSentTo($notary, PortalNotification::class, fn ($n) => $n->url === route('notary.pending'));
        $this->actingAs($notary->fresh())->get(route('notary.pending'))->assertOk();

        Notification::assertNotSentTo($notary, PortalNotification::class, fn ($n) => $n->url === route('dashboard'));
    }

    // ---- 1.5: approval refuses a deactivated account

    public function test_a_deactivated_notary_cannot_be_approved(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $notary = $this->user('notary', ['notary_approved_at' => null, 'deactivated_at' => now()]);

        $this->actingAs($admin)->post(route('admin.notaries.approve', $notary))
            ->assertSessionHas('error', 'This account is deactivated. Reactivate it before approving it.');

        $this->assertNull($notary->fresh()->notary_approved_at);
        $this->assertSame(0, AuditLog::where('action', 'admin.notary_approved')->count());
        Notification::assertNothingSent();

        $this->actingAs($admin)->get(route('admin.notaries.index'))->assertOk()
            ->assertDontSee(route('admin.notaries.approve', $notary), false);
    }

    // ---- 1.6: one email and one mobile per active account

    public function test_an_email_differing_only_in_case_is_refused(): void
    {
        $this->user('public', [])->forceFill(['email' => 'taken.address@example.test'])->save();

        $this->signUp(['email' => 'Taken.Address@Example.TEST'])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(0, User::whereRaw('LOWER(email) = ?', ['taken.address@example.test'])->where('role', 'notary')->count());
    }

    public function test_a_mobile_written_another_way_is_refused(): void
    {
        $this->user('public', ['mobile' => '9555512345']);

        foreach (['+91 95555 12345', '095555-12345', '+91-9555512345'] as $written) {
            $this->signUp(['mobile' => $written])->assertStatus(422);
        }

        $this->assertSame(1, User::where('mobile', '9555512345')->count());
    }

    public function test_a_deleted_citizens_identity_is_free_but_a_deleted_admins_is_not(): void
    {
        Notification::fake();
        $actor = $this->user('admin');
        $this->user('admin');
        $citizen = $this->user('public', ['email' => 'former.citizen@example.test', 'mobile' => '9555500077']);
        $otherAdmin = $this->user('admin', ['email' => 'former.admin@example.test', 'mobile' => '9555500088']);

        $service = app(AccountDeletionService::class);
        $service->delete($actor, $citizen, 'Synthetic test deletion.');
        $service->delete($actor, $otherAdmin, 'Synthetic test deletion.');

        $this->signUp(['email' => 'former.citizen@example.test', 'mobile' => '9555500077'])->assertOk();
        $this->assertTrue(Auth()->check());
        auth()->logout();

        $this->signUp(['email' => 'former.admin@example.test', 'mobile' => '9555500099'])->assertStatus(422);
        $this->signUp(['email' => 'fresh.person@example.test', 'mobile' => '9555500088'])->assertStatus(422);
    }

    public function test_two_sign_ups_at_the_same_moment_leave_one_account_and_a_clear_message(): void
    {
        // The other sign-up commits between this one's validation and its insert: right after the
        // last uniqueness check (the mobile), and outside this request's own transaction.
        // The column is quoted "mobile" by SQLite and `mobile` by MariaDB: match either.
        $done = false;
        DB::listen(function ($query) use (&$done) {
            if (! $done && str_contains($query->sql, 'count(*)') && preg_match('/["`]mobile["`]/', $query->sql)) {
                $done = true;
                DB::table('users')->insert([
                    'name' => 'Other', 'email' => 'race@example.test', 'mobile' => '9555500055',
                    'password' => Hash::make(self::PASSWORD), 'role' => 'public', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->signUp(['email' => 'race@example.test', 'mobile' => '9555500056']);

        // The race really happened on this database: otherwise the test proves nothing.
        $this->assertTrue($done, 'The competing sign-up was never staged: the query matcher did not fire.');

        $response->assertStatus(422)->assertJsonPath('message', 'This email or mobile number is already registered.');

        $this->assertSame(1, User::where('email', 'race@example.test')->count());
        $this->assertGuest();
    }

    // ---- 1.7: every problem is returned, and no password can reach the address bar

    public function test_every_validation_problem_is_returned_at_once(): void
    {
        $response = $this->signUp(['name' => '', 'email' => 'not-an-email', 'mobile' => '12', 'password' => 'short', 'password_confirmation' => 'short', 'role' => 'admin'])
            ->assertStatus(422)->assertJson(['success' => false]);

        // One or more messages for each of the five bad fields (the password policy reports every
        // requirement 'short' misses: length, mixed case, number, special character).
        $errors = $response->json('errors');
        foreach (['name', 'email', 'mobile', 'password', 'role'] as $field) {
            $this->assertNotEmpty(array_filter($errors, fn ($m) => str_contains(strtolower($m), $field)), "a message for {$field}");
        }
        $this->assertCount(8, $errors, '1 name + 1 email + 1 mobile + 4 password + 1 role');
        $this->assertSame($response->json('errors.0'), $response->json('message'));
        $this->assertSame(0, User::count());
    }

    public function test_the_sign_up_form_posts_even_without_its_script(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form id="registerForm" method="POST" action="'.preg_quote(route('register.submit'), '/').'"/', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }
}
