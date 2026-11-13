<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Module 11: consent is required, recorded with its version, kept as history and exportable. */
class PrivacyConsentTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** Audit Module 11: the export left out support tickets, notifications, contact messages and sign-ins. */
    public function test_the_data_export_includes_every_kind_of_record_the_policy_lists(): void
    {
        $user = $this->user();
        $other = $this->user();
        $staff = $this->user('admin');
        $ticket = \App\Models\SupportTicket::create(['ticket_number' => 'ST-EXPORT-1', 'user_id' => $user->id, 'subject' => 'My receipt', 'category' => 'account']);
        \App\Models\SupportTicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => 'Please help.']);
        \App\Models\SupportTicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'Done.']);
        \App\Models\SupportTicket::create(['ticket_number' => 'ST-OTHER-1', 'user_id' => $other->id, 'subject' => 'Not yours']);
        \App\Models\ContactMessage::create(['name' => 'Demo', 'email' => strtoupper($user->email), 'subject' => 'Hello', 'message' => 'A question.']);
        \App\Models\ContactMessage::create(['name' => 'Other', 'email' => $other->email, 'message' => 'Not yours.']);
        $user->notify(new \App\Notifications\PortalNotification('Your ticket was answered', 'See the reply.', url('/support')));
        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'Demo-pass-123'])->assertOk();

        $data = json_decode($this->actingAs($user)->get(route('privacy-center.export'))->assertOk()->getContent(), true);

        $this->assertSame(['ST-EXPORT-1'], array_column($data['support_tickets'] ?? [], 'ticket_number'));
        $this->assertSame(['you', 'support'], array_column($data['support_tickets'][0]['messages'] ?? [], 'from'));
        $this->assertStringNotContainsString($staff->name, json_encode($data['support_tickets'] ?? []), 'Staff are not named.');
        $this->assertSame(['A question.'], array_column($data['contact_messages'] ?? [], 'message'));
        $this->assertCount(1, $data['notifications'] ?? []);
        $this->assertContains('auth.login', array_column($data['sign_in_activity'] ?? [], 'action'));
        $this->assertStringNotContainsString('Not yours', json_encode($data));
    }

    private function user(string $role = 'public', array $extra = []): User
    {
        $this->n++;
        $user = User::create([
            'name' => "Demo Person {$this->n}", 'email' => "consent{$this->n}@example.test", 'mobile' => '93200'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('Demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()] + $extra)->save();

        return $user;
    }

    private function signUp(array $override = [])
    {
        Cache::flush();

        return $this->postJson(route('register.submit'), array_merge([
            'name' => 'Consent Person', 'email' => 'consent.new@example.test', 'mobile' => '9555511111',
            'password' => 'Demo-pass-123', 'password_confirmation' => 'Demo-pass-123', 'role' => 'public', 'accept_terms' => '1',
        ], $override));
    }

    public function test_sign_up_without_consent_is_refused_and_creates_nothing(): void
    {
        Notification::fake();

        foreach ([[], ['accept_terms' => '0'], ['accept_terms' => '']] as $missing) {
            $data = $missing === [] ? ['accept_terms' => null] : $missing;
            $this->signUp($data)->assertStatus(422)
                ->assertJsonPath('message', 'Please accept the Terms of Use and the Privacy Policy to create an account.');
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::where('action', ConsentService::ACTION)->count());
    }

    public function test_sign_up_records_consent_with_the_versions_in_force(): void
    {
        Notification::fake();
        config(['privacy.terms_version' => 'T-9', 'privacy.privacy_version' => 'P-9']);

        $this->signUp()->assertOk()->assertJson(['success' => true]);

        $user = User::sole();
        $entry = AuditLog::where('action', ConsentService::ACTION)->sole();
        $this->assertSame($user->id, (int) $entry->user_id);
        $this->assertSame($user->id, (int) $entry->model_id);
        $this->assertSame(['terms_version' => 'T-9', 'privacy_version' => 'P-9', 'source' => 'registration'], $entry->meta);
        $this->assertNotNull($entry->created_at);
        $this->assertTrue(app(ConsentService::class)->isCurrent($user));
    }

    public function test_the_form_has_an_unticked_required_consent_box(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="checkbox" name="accept_terms" id="accept_terms" value="1" required>/', $html);
        $this->assertStringNotContainsString('name="accept_terms" id="accept_terms" value="1" required checked', $html);
        $this->assertStringContainsString("accept_terms: form.accept_terms.checked ? '1' : ''", $html);
    }

    public function test_a_new_version_asks_again_and_keeps_the_old_acceptance(): void
    {
        $user = $this->user();
        app(ConsentService::class)->record($user, 'registration');

        $this->actingAs($user)->get(route('privacy-center.index'))->assertOk()
            ->assertSee('You have accepted the current Terms of Use and Privacy Policy.')
            ->assertDontSee(route('privacy-center.consent'), false);

        config(['privacy.privacy_version' => '2099-01-01']);

        $this->actingAs($user)->get(route('privacy-center.index'))->assertOk()
            ->assertSee('The Terms of Use or Privacy Policy have changed since you last accepted them.')
            ->assertSee(route('privacy-center.consent'), false);

        $this->actingAs($user)->post(route('privacy-center.consent'))->assertSessionHasErrors('accept_terms');
        $this->assertSame(1, AuditLog::where('action', ConsentService::ACTION)->count());

        $this->actingAs($user)->post(route('privacy-center.consent'), ['accept_terms' => '1'])->assertSessionHas('status', 'Thank you. Your acceptance has been recorded.');
        $this->actingAs($user)->post(route('privacy-center.consent'), ['accept_terms' => '1'])->assertSessionHas('status', 'You have already accepted the current versions.');

        $history = app(ConsentService::class)->history($user);
        $this->assertCount(2, $history);
        $this->assertSame('2099-01-01', $history->first()->meta['privacy_version']);
        $this->assertSame('privacy_center', $history->first()->meta['source']);
        $this->assertNotSame('2099-01-01', $history->last()->meta['privacy_version']);
    }

    public function test_an_account_with_no_record_is_asked_to_accept(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('privacy-center.index'))->assertOk()
            ->assertSee('We have no record of your acceptance of the Terms of Use and Privacy Policy.');
    }

    public function test_the_data_export_holds_the_persons_own_consent_history_only(): void
    {
        $user = $this->user();
        $other = $this->user();
        app(ConsentService::class)->record($user, 'registration');
        app(ConsentService::class)->record($other, 'registration');

        $data = json_decode($this->actingAs($user)->get(route('privacy-center.export'))->assertOk()->getContent(), true);

        $this->assertCount(1, $data['consents']);
        $this->assertSame('registration', $data['consents'][0]['source']);
        $this->assertSame(config('privacy.terms_version'), $data['consents'][0]['terms_version']);
    }

    public function test_privacy_requests_of_each_type_are_recorded_once_while_open(): void
    {
        $user = $this->user();

        foreach (PrivacyRequest::TYPES as $type) {
            $this->actingAs($user)->post(route('privacy-center.store'), ['type' => $type, 'details' => 'Synthetic request.'])
                ->assertSessionHas('status', 'Your request has been recorded.');
            $this->actingAs($user)->post(route('privacy-center.store'), ['type' => $type])
                ->assertSessionHas('error', 'You already have an open request of this type.');
        }

        $this->assertSame(count(PrivacyRequest::TYPES), PrivacyRequest::where('user_id', $user->id)->count());
        $this->assertSame(count(PrivacyRequest::TYPES), AuditLog::where('action', 'privacy.requested')->count());
        $this->actingAs($user)->post(route('privacy-center.store'), ['type' => 'everything'])->assertSessionHasErrors('type');
    }

    public function test_only_administrators_see_and_decide_privacy_requests(): void
    {
        $user = $this->user();
        $other = $this->user();
        $admin = $this->user('admin');
        $this->actingAs($user)->post(route('privacy-center.store'), ['type' => 'correction', 'details' => 'My name is spelt wrongly.']);
        $privacy = PrivacyRequest::sole();

        $this->actingAs($other)->get(route('privacy-center.index'))->assertOk()->assertDontSee('My name is spelt wrongly.');
        $this->actingAs($user)->get(route('admin.security.privacy'))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.security.privacy.update', $privacy), ['status' => 'completed'])->assertForbidden();

        $this->actingAs($admin)->get(route('admin.security.privacy'))->assertOk()->assertSee('My name is spelt wrongly.');
    }

    public function test_the_admin_user_page_shows_consent_state(): void
    {
        $admin = $this->user('admin');
        $user = $this->user();

        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertSee('Not recorded');

        app(ConsentService::class)->record($user, 'registration');
        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertSee('Current');
    }

    public function test_the_policy_shows_its_version_and_retention_rules_and_claims_no_compliance(): void
    {
        $this->get(route('privacy'))->assertOk()
            ->assertSee('Version: '.config('privacy.privacy_version'))
            ->assertSee('Audit trail')
            ->assertSee('makes no claim of legal compliance')
            ->assertDontSee('GDPR');
        $this->get(route('terms'))->assertOk()->assertSee('Version: '.config('privacy.terms_version'));
    }
}
