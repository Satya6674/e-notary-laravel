<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminMfa;
use App\Support\AdminReauth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SEC-2: which Admin actions need the two-factor step-up, deny by default. Every route an Admin can
 * reach that changes something, and every Admin export or download, must carry "admin.reauth" or
 * be listed below with the reason it is exempt (see docs/E_NOTARY_SECURITY_REMEDIATION_PLAN.md §5
 * and §11.4). A new route that is neither fails this test.
 */
class AdminStepUpCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $adminPasswordConfirmed = false;

    /** Routes that change data but need no step-up, with the reason. */
    private const EXEMPT = [
        // The step-up and two-factor mechanism itself.
        'admin.reauth.confirm' => 'is the step-up',
        'admin.mfa.challenge.verify' => 'is the sign-in second factor',
        'admin.mfa.confirm' => 'confirms a new factor with a code',
        'admin.mfa.enroll' => 'bootstrap: checks the password itself (an Admin without a factor cannot pass a code step-up)',
        // The Admin's own account and own records.
        'account.previous.new' => 'own account',
        'account.previous.confirm' => 'own account',
        'account.previous.send' => 'own account',
        'account.recovery.cancel' => 'own recovery request',
        'verification.send' => 'own email verification',
        'verification.code' => 'own email verification',
        'notifications.read-all' => 'own notifications',
        'privacy-center.store' => 'own privacy request',
        'privacy-center.consent' => 'own consent',
        'profile.photo.store' => 'own photo',
        'profile.photo.destroy' => 'own photo',
        'support.store' => 'own new ticket',
        'appointments.store' => 'own booking (citizens)',
        // Low-impact operational actions, reviewed one by one (plan §11.4).
        'admin.delivery.retry' => 're-sends an unchanged failed message to its original recipient',
        'admin.messages.read' => 'a read flag on a contact message',
        'admin.recovery.review' => 'a status label; the decisions are gated',
        'admin.security.check' => 'checks provider logins, sends nothing, changes no setting',
        'admin.users.deletion.retry' => 're-sends a notice without a link or token',
        'support.close' => 'routine support work in which the Admin is a party',
        'support.reply' => 'routine support work in which the Admin is a party',
        'support.triage' => 'internal priority and assignee',
    ];

    /** Read-only pages an Admin opens that look like files but are exempt, with the reason. */
    private const EXEMPT_FILES = [
        'privacy-center.export' => 'the Admin\'s own data',
        'profile.photo.show' => 'an inline profile image',
        'reports.index' => 'a read-only page; its exports carry the step-up',
    ];

    private User $admin;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = $this->person('admin', 'coverage.admin@example.test', '9478000001');
        $mfa = app(AdminMfa::class);
        $this->secret = $mfa->start($this->admin);
        $mfa->confirm($this->admin, AdminMfa::codeAt($this->secret, time()));
    }

    private function person(string $role, string $email, string $mobile): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('Coverage-demo-1'),
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null])->save();

        return $user->fresh();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession([AdminMfa::SESSION_KEY => $this->admin->id]);
    }

    /** Every route an authenticated Admin can reach, by name. */
    private function adminRoutes(): array
    {
        $reachable = [];

        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $roles = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'role:'));

            if (! in_array('auth', $middleware, true) || in_array('guest', $middleware, true) || in_array('notary', $middleware, true)
                || ($roles && ! array_filter($roles, fn ($m) => str_contains($m, 'admin')))) {
                continue;
            }

            $reachable[$route->getName() ?? $route->uri()] = $route;
        }

        return $reachable;
    }

    public function test_every_admin_route_that_changes_something_has_the_step_up_or_a_listed_reason(): void
    {
        $unclassified = [];

        foreach ($this->adminRoutes() as $name => $route) {
            $changes = array_diff($route->methods(), ['GET', 'HEAD']) !== [];
            $isFile = in_array('GET', $route->methods(), true)
                && preg_match('/export|download|pdf|csv|xlsx|preview|attachment|certificate|receipt|report|photo/i', $name.' '.$route->uri());

            if (! $changes && ! $isFile) {
                continue;
            }

            $guarded = in_array('admin.reauth', $route->gatherMiddleware(), true);
            $exempt = $changes ? isset(self::EXEMPT[$name]) : isset(self::EXEMPT_FILES[$name]);

            if (! $guarded && ! $exempt) {
                $unclassified[] = $name;
            }

            $this->assertFalse($guarded && $exempt, "{$name} is guarded and also listed as exempt");
        }

        $this->assertSame([], $unclassified, 'These Admin routes need "admin.reauth" or a documented exemption.');
    }

    public function test_the_exempt_lists_name_only_routes_that_exist(): void
    {
        foreach ([...array_keys(self::EXEMPT), ...array_keys(self::EXEMPT_FILES)] as $name) {
            $this->assertNotNull(app('router')->getRoutes()->getByName($name), $name);
        }
    }

    public function test_the_reviewed_operational_routes_now_carry_the_step_up(): void
    {
        foreach (['admin.email-replacements.cancel', 'admin.recovery.retry', 'admin.users.deletion.reset-link', 'appointments.update',
            'admin.requests.approve', 'admin.requests.reject', 'admin.documents.destroy', 'admin.documents.download', 'admin.settings.update',
            'admin.analytics.csv', 'admin.audit-logs.pdf', 'reports.csv', 'reports.pdf', 'documents.preview', 'support.attachment'] as $name) {
            $this->assertContains('admin.reauth', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_settings_change_nothing_without_a_fresh_code_and_the_typed_text_comes_back(): void
    {
        Setting::put('portal_notice', 'Old notice.');

        $this->asAdmin()->from(route('settings.edit'))
            ->put(route('admin.settings.update'), ['portal_notice' => 'New notice text.', 'support_email' => 'support@example.test', 'default_locale' => 'en'])
            ->assertRedirect(route('admin.reauth.show'));
        $this->assertSame('Old notice.', Setting::get('portal_notice'));

        $this->post(route('admin.reauth.confirm'), ['code' => AdminMfa::codeAt($this->secret, time() + 30)])
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHasInput('portal_notice', 'New notice text.');
    }

    public function test_an_export_is_refused_without_a_fresh_code_and_opens_directly_after_one(): void
    {
        $response = $this->asAdmin()->from(route('admin.analytics.index'))->get(route('admin.analytics.csv'));

        $response->assertRedirect(route('admin.reauth.show'));
        $this->assertStringNotContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $this->post(route('admin.reauth.confirm'), ['code' => AdminMfa::codeAt($this->secret, time() + 30)])
            ->assertRedirect(route('admin.analytics.csv'));
        $this->get(route('admin.analytics.csv'))->assertOk();
    }

    public function test_deleting_a_message_needs_the_step_up_and_json_gets_423(): void
    {
        $message = ContactMessage::create(['name' => 'Demo', 'email' => 'visitor@example.test', 'subject' => 'Hello', 'message' => 'A question.']);

        $this->asAdmin()->delete(route('admin.messages.destroy', $message))->assertRedirect(route('admin.reauth.show'));
        $this->asAdmin()->deleteJson(route('admin.messages.destroy', $message))->assertStatus(423);

        $this->assertNotNull($message->fresh());
    }

    public function test_notaries_use_shared_reports_without_any_step_up(): void
    {
        $notary = $this->person('notary', 'coverage.notary@example.test', '9478000002');

        $this->actingAs($notary)->get(route('reports.csv'))->assertOk();
        $this->asAdmin()->get(route('reports.csv'))->assertRedirect(route('admin.reauth.show'));
    }

    public function test_exempt_operational_routes_still_work_without_a_fresh_code(): void
    {
        $message = ContactMessage::create(['name' => 'Demo', 'email' => 'visitor@example.test', 'subject' => 'Hello', 'message' => 'A question.']);

        $this->asAdmin()->post(route('admin.messages.read', $message))->assertRedirect(route('admin.messages.index'));
        $this->assertTrue((bool) $message->fresh()->is_read);

        $this->asAdmin()->post(route('notifications.read-all'))->assertRedirect();
        $this->assertFalse(session()->has(AdminReauth::SESSION_KEY));
    }
}
