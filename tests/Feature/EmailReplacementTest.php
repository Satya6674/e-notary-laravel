<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\EmailReplacementLinkNotification;
use App\Services\AccountDeletionService;
use App\Services\EmailReplacementService;
use App\Support\LogMailRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Lost-mailbox recovery: replacing the address of a restored, locked account (owner decision,
 * 2026-11-04). Nothing changes until the new address opens its single-use link; then the account
 * moves there, stays locked, and gets its password link there. Every step is checked and audited,
 * and no token appears outside the link. Mail is the array mailer; every account is synthetic.
 */
class EmailReplacementTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'lost.mailbox@example.test';

    private const NEW = 'fresh.mailbox@example.test';

    private User $admin;

    private User $account;

    private SupportTicket $ticket;

    /** @var list<string> tokens of the links sent */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->person('admin', 'support.admin@example.test', '9530000001');
        $this->person('admin', 'second.admin@example.test', '9530000002');

        // A restored account: locked until a new password is set, with its link on the old address.
        $this->account = $this->person('public', self::OLD, '9530000010');
        $this->actingAs($this->admin);
        $service = app(AccountDeletionService::class);
        $service->delete($this->admin, $this->account, 'Synthetic deletion for testing.');
        $service->restore($this->admin, $this->account->fresh(), 'Synthetic restore for testing.');
        $this->account = $this->account->fresh();
        $this->assertTrue($this->account->mustResetPassword());

        // The person asked Support from a new account of their own.
        $helper = $this->person('public', 'person.now@example.test', '9530000011');
        $this->ticket = SupportTicket::create(['ticket_number' => 'ST-LOST-1', 'user_id' => $helper->id, 'subject' => 'I lost my old mailbox']);

        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if ($event->notification instanceof EmailReplacementLinkNotification) {
                $this->tokens[] = $event->notification->token;
            }
        });

        app('mailer')->getSymfonyTransport()->flush();
    }

    private function person(string $role, string $email, string $mobile): User
    {
        $user = User::create(['name' => 'Person '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make('Old-demo-pass-1'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function form(array $override = []): array
    {
        return $override + [
            'new_email' => self::NEW, 'support_ticket_id' => $this->ticket->id, 'identity_check' => 'video_call',
            'identity_note' => 'Video call, face matched the profile photo on record.',
            'reason' => 'Owner no longer has access to the old mailbox; identity confirmed.', 'identity_confirmed' => '1',
        ];
    }

    private function start(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.users.email-replacement.store', $this->account), $this->form($override));
    }

    private function link(EmailReplacement $replacement, ?string $token = null): string
    {
        return route('email-replacement.show', ['replacement' => $replacement->id, 'token' => $token ?? $this->tokens[0]]);
    }

    private function confirm(EmailReplacement $replacement, ?string $token = null)
    {
        Auth::logout();

        return $this->post($this->link($replacement, $token));
    }

    private function mails(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => (string) $m->getOriginalMessage()->getSubject(),
                'body' => (string) $m->getOriginalMessage()->getTextBody().(string) $m->getOriginalMessage()->getHtmlBody()])->values()->all();
    }

    private function resetTokensTo(string $address): array
    {
        return collect($this->mails())->filter(fn ($m) => $m['to'] === $address && str_contains($m['subject'], 'Reset your'))
            ->map(fn ($m) => preg_match('#/reset-password/([^?"\s]+)#', $m['body'], $f) ? $f[1] : null)->filter()->values()->all();
    }

    // ---- The whole way through

    public function test_the_new_address_is_used_only_after_it_confirms_and_the_account_stays_locked(): void
    {
        $this->start()->assertSessionHas('status');
        $replacement = EmailReplacement::sole();

        // Nothing changed yet.
        $this->assertSame(self::OLD, $this->account->fresh()->email);
        $this->assertTrue($replacement->isPending());
        $this->assertCount(1, $this->tokens);

        // The link to the new address only; the old address is told, with the new one masked.
        $mails = collect($this->mails());
        $this->assertSame([self::NEW], $mails->filter(fn ($m) => str_contains($m['subject'], 'Confirm your new'))->pluck('to')->all());
        $alert = $mails->first(fn ($m) => str_contains($m['subject'], 'Security alert: a new email address was requested'));
        $this->assertSame(self::OLD, $alert['to']);
        $this->assertStringContainsString('fr***@example.test', $alert['body']);
        $this->assertStringNotContainsString(self::NEW, $alert['body']);
        $this->assertStringNotContainsString($this->tokens[0], $alert['body']);

        // Opening the link changes nothing; it shows a button, sends no referrer and is not cached.
        Auth::logout();
        $this->get($this->link($replacement))->assertOk()->assertSee('Confirm this email address')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(self::OLD, $this->account->fresh()->email);

        // Confirming moves the account, verified, and still locked.
        $this->confirm($replacement)->assertRedirect(route('login'));
        $moved = $this->account->fresh();
        $this->assertSame(self::NEW, $moved->email);
        $this->assertNotNull($moved->email_verified_at);
        $this->assertSame('replacement_link', $moved->email_verification_method);
        $this->assertTrue($moved->mustResetPassword(), 'Locked until a new password is set.');
        $this->assertSame(EmailReplacement::VERIFIED, $replacement->fresh()->status);
        $this->postJson(route('login.submit'), ['mobile' => self::NEW, 'password' => 'Old-demo-pass-1'])->assertStatus(401);

        // The password link goes to the new address only, and it unlocks the account.
        $this->assertSame([], $this->resetTokensTo(self::OLD), 'Nothing more to the lost address.');
        $tokens = $this->resetTokensTo(self::NEW);
        $this->assertCount(1, $tokens);
        $this->post(route('password.update'), ['token' => $tokens[0], 'email' => self::NEW, 'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])->assertRedirect(route('home'));
        $this->assertFalse($moved->fresh()->mustResetPassword());
    }

    public function test_the_old_address_reset_link_dies_when_the_new_address_confirms(): void
    {
        $oldLink = Password::broker()->createToken($this->account);
        $this->start();
        $this->confirm(EmailReplacement::sole());

        Auth::logout();
        $this->post(route('password.update'), ['token' => $oldLink, 'email' => self::OLD, 'password' => 'Takeover-pass-1', 'password_confirmation' => 'Takeover-pass-1'])->assertSessionHasErrors();
        $this->assertSame(1, DB::table(config('auth.passwords.users.table'))->count(), 'Only the new address has a link.');
        $this->assertSame(self::NEW, DB::table(config('auth.passwords.users.table'))->value('email'));
    }

    public function test_every_step_is_audited_by_the_right_actor_and_no_token_is_recorded_or_flashed(): void
    {
        $response = $this->start();
        $replacement = EmailReplacement::sole();
        $this->confirm($replacement);

        $started = AuditLog::where('action', 'admin.email_replacement_started')->sole();
        $this->assertSame($this->admin->id, $started->user_id);
        $this->assertSame($this->account->id, (int) $started->model_id);
        $this->assertSame(['reference' => $replacement->reference, 'source' => 'support_ticket', 'source_id' => $this->ticket->id, 'identity_check' => 'video_call'], $started->meta);
        $replaced = AuditLog::where('action', 'account.email_replaced')->sole();
        $this->assertNull($replaced->user_id);
        $this->assertSame($this->account->id, (int) $replaced->model_id);

        // The token lives in the link only: not in records, flash, the redirect or the database.
        $token = $this->tokens[0];
        $this->assertStringNotContainsString($token, AuditLog::pluck('meta')->toJson());
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $this->assertStringNotContainsString($token, json_encode(DB::table('email_replacements')->get()));
        $this->assertSame(EmailReplacement::hashToken($token), DB::table('email_replacements')->value('token_hash'));
        $this->assertStringNotContainsString($token, json_encode(DB::table('message_deliveries')->get()));
    }

    public function test_history_is_kept_and_nothing_is_merged(): void
    {
        $before = AuditLog::pluck('id')->all();
        $deletions = AccountDeletion::count();
        $helper = User::where('email', 'person.now@example.test')->sole();

        $this->start();
        $this->confirm(EmailReplacement::sole());

        $this->assertSame([], array_values(array_diff($before, AuditLog::pluck('id')->all())), 'No entry removed.');
        $this->assertSame($deletions, AccountDeletion::count());
        $this->assertNotNull(AccountDeletion::where('user_id', $this->account->id)->sole()->restored_at);
        // The person's other account is untouched: no merge.
        $this->assertSame('person.now@example.test', $helper->fresh()->email);
        $this->assertFalse($helper->fresh()->isDeleted());
    }

    // ---- Replay, expiry, wrong links, cancellation

    public function test_a_used_link_cannot_be_used_again(): void
    {
        $this->start();
        $replacement = EmailReplacement::sole();
        $this->confirm($replacement)->assertRedirect(route('login'));

        $this->confirm($replacement)->assertStatus(410)->assertSee('This link is no longer valid.');
        $this->get($this->link($replacement))->assertOk()->assertDontSee('Confirm this email address</button>', false);

        $this->assertCount(1, $this->resetTokensTo(self::NEW), 'One password link.');
        $this->assertSame(1, AuditLog::where('action', 'account.email_replacement_refused')->where('meta->result', EmailReplacementService::USED)->count());
    }

    public static function expiryOffsets(): array
    {
        return [
            'one second before 24 hours' => [-1, true],
            'exactly 24 hours' => [0, false],
            'one second after 24 hours' => [1, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expiryOffsets')]
    public function test_the_link_expires_after_24_hours_to_the_second(int $offset, bool $works): void
    {
        $this->start();
        $replacement = EmailReplacement::sole();

        $this->travelTo($replacement->expires_at->copy()->addSeconds($offset));

        if ($works) {
            $this->confirm($replacement)->assertRedirect(route('login'));
            $this->assertSame(self::NEW, $this->account->fresh()->email);

            return;
        }

        $this->confirm($replacement)->assertStatus(410);
        $this->assertSame(self::OLD, $this->account->fresh()->email);
        $this->assertSame(EmailReplacement::EXPIRED, $replacement->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'account.email_replacement_expired')->count());
    }

    public function test_a_wrong_token_changes_nothing_and_is_recorded(): void
    {
        $this->start();
        $replacement = EmailReplacement::sole();

        $this->confirm($replacement, str_repeat('Z', 64))->assertStatus(410);
        $this->get(route('email-replacement.show', ['replacement' => 999999, 'token' => str_repeat('a', 64)]))->assertOk()->assertSee('This link is no longer valid.');

        $this->assertSame(self::OLD, $this->account->fresh()->email);
        $this->assertTrue($replacement->fresh()->isPending(), 'A wrong guess does not end the real link.');
        $this->assertSame(1, AuditLog::where('action', 'account.email_replacement_refused')->where('meta->result', EmailReplacementService::INVALID)->count());
    }

    public function test_a_cancelled_replacement_link_no_longer_works(): void
    {
        $this->start();
        $replacement = EmailReplacement::sole();

        $this->actingAs($this->admin)->post(route('admin.email-replacements.cancel', $replacement), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('admin.email-replacements.cancel', $replacement), ['reason' => 'Person withdrew the request.'])->assertSessionHas('status');

        $this->confirm($replacement)->assertStatus(410);
        $this->assertSame(self::OLD, $this->account->fresh()->email);
        $this->assertSame(EmailReplacement::CANCELLED, $replacement->fresh()->status);
        $this->assertSame($this->admin->id, AuditLog::where('action', 'admin.email_replacement_cancelled')->sole()->user_id);

        // A new one may be started afterwards.
        $this->start()->assertSessionHas('status');
        $this->assertSame(1, EmailReplacement::where('status', EmailReplacement::PENDING)->count());
    }

    // ---- Who may, and for which account

    public function test_only_an_active_administrator_reaches_it(): void
    {
        $citizen = User::where('email', 'person.now@example.test')->sole();

        $this->actingAs($citizen)->get(route('admin.users.email-replacement', $this->account))->assertStatus(403);
        $this->actingAs($citizen)->post(route('admin.users.email-replacement.store', $this->account), $this->form())->assertStatus(403);
        $this->assertSame(0, EmailReplacement::count());

        // Deactivated while the page was open: refused under the lock.
        $stale = $this->admin->fresh();
        DB::table('users')->where('id', $this->admin->id)->update(['deactivated_at' => now()]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(EmailReplacementService::class)->start($stale, $this->account, $this->form());
    }

    public static function refusedAccounts(): array
    {
        return [
            'not locked (already has its own password)' => ['unlocked', 'Only a restored account that is still waiting for its new password can have its address replaced here.'],
            'an administrator account' => ['admin', 'The address of a current or former administrator account cannot be replaced here.'],
            'a former administrator account' => ['former_admin', 'The address of a current or former administrator account cannot be replaced here.'],
            'archived' => ['deleted', 'Restore this account before replacing its address.'],
            'deactivated' => ['deactivated', 'This account is deactivated. Reactivate it first if that was a mistake.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAccounts')]
    public function test_an_account_this_does_not_apply_to_is_refused(string $case, string $message): void
    {
        match ($case) {
            'unlocked' => DB::table('users')->where('id', $this->account->id)->update(['password_reset_required_at' => null]),
            'admin' => DB::table('users')->where('id', $this->account->id)->update(['role' => 'admin']),
            'former_admin' => AuditLog::recordOrFail('admin.settings_updated', null, [], $this->account->id),
            'deleted' => DB::table('users')->where('id', $this->account->id)->update(['deleted_at' => now()]),
            'deactivated' => DB::table('users')->where('id', $this->account->id)->update(['deactivated_at' => now()]),
        };

        $this->start()->assertSessionHas('error', $message);
        $this->assertSame(0, EmailReplacement::count());
        $this->assertSame([], $this->mails());
    }

    public function test_an_administrator_cannot_replace_their_own_address(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.email-replacement.store', $this->admin), $this->form())->assertSessionHas('error', 'You cannot replace the address of your own account.');
        $this->assertSame(0, EmailReplacement::count());
    }

    // ---- What the administrator must record

    public static function badForms(): array
    {
        return [
            'reason too short' => [['reason' => 'Lost mailbox.'], 'reason'],
            'identity not confirmed' => [['identity_confirmed' => null], 'identity_confirmed'],
            'a document number in the note' => [['identity_note' => 'Checked Aadhaar 123412341234 on video.'], 'identity_note'],
            'note too short' => [['identity_note' => 'ok'], 'identity_note'],
            'unknown check method' => [['identity_check' => 'gut_feeling'], 'identity_check'],
            'not an email address' => [['new_email' => 'not-an-address'], 'new_email'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badForms')]
    public function test_the_form_requires_a_reason_and_a_clean_record_of_the_check(array $override, string $field): void
    {
        $this->start($override)->assertSessionHasErrors($field);
        $this->assertSame(0, EmailReplacement::count());
    }

    public function test_the_request_must_come_through_support_and_the_address_must_be_free(): void
    {
        $this->start(['support_ticket_id' => null])->assertSessionHas('error', 'Link the support ticket or contact message the person sent.');
        $this->start(['support_ticket_id' => 999999])->assertSessionHas('error', 'Link the support ticket or contact message the person sent.');
        $this->start(['new_email' => 'SUPPORT.ADMIN@example.test'])->assertSessionHas('error', 'Another account uses that address. Nothing was changed.');
        $this->start(['new_email' => strtoupper(self::OLD)])->assertSessionHas('error', 'The new address is the same as the current one.');
        $this->assertSame(0, EmailReplacement::count());

        // One waiting replacement per account.
        $this->start()->assertSessionHas('status');
        $this->start(['new_email' => 'another.mailbox@example.test'])->assertSessionHas('error', 'A new address is already waiting to be confirmed for this account. Cancel it first.');
        $this->assertSame(1, EmailReplacement::count());
    }

    public function test_an_address_already_proposed_for_another_account_is_refused(): void
    {
        $this->start()->assertSessionHas('status');

        $other = $this->person('public', 'other.locked@example.test', '9530000020');
        app(AccountDeletionService::class)->delete($this->admin, $other, 'Synthetic deletion.');
        app(AccountDeletionService::class)->restore($this->admin, $other->fresh(), 'Synthetic restore.');
        $this->actingAs($this->admin)->post(route('admin.users.email-replacement.store', $other), $this->form())
            ->assertSessionHas('error', 'That address is already proposed for another account, or a replacement is already waiting here. Nothing was changed.');
    }

    // ---- Checked again when the link is used

    public static function changedMeanwhile(): array
    {
        return [
            'the owner set a password through the old link' => ['password_set'],
            'the account was archived again' => ['archived'],
            'someone registered the new address' => ['taken'],
            'the account\'s address was changed' => ['moved'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedMeanwhile')]
    public function test_a_link_whose_situation_changed_is_refused_and_changes_nothing(string $case): void
    {
        $this->start();
        $replacement = EmailReplacement::sole();

        match ($case) {
            'password_set' => DB::table('users')->where('id', $this->account->id)->update(['password_reset_required_at' => null]),
            'archived' => DB::table('users')->where('id', $this->account->id)->update(['deleted_at' => now()]),
            'taken' => $this->person('public', self::NEW, '9530000030'),
            'moved' => DB::table('users')->where('id', $this->account->id)->update(['email' => 'moved.elsewhere@example.test']),
        };
        $emailBefore = $this->account->fresh()->email;

        $this->confirm($replacement)->assertStatus(410);

        $this->assertSame($emailBefore, $this->account->fresh()->email);
        $this->assertSame([], $this->resetTokensTo(self::NEW));
        $this->assertSame(1, AuditLog::where('action', 'account.email_replacement_refused')->where('meta->result', EmailReplacementService::UNAVAILABLE)->count());
    }

    // ---- Logs

    public function test_the_link_token_is_not_written_to_the_mail_log(): void
    {
        $log = storage_path('framework/testing/replacement-log-'.getmypid().'-'.bin2hex(random_bytes(4)).'.log');
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'replacement-test', 'logging.channels.replacement-test' => ['driver' => 'single', 'path' => $log]]);
        app('mail.manager')->forgetMailers();

        $this->start()->assertSessionHas('status');

        foreach (app('log')->channel('replacement-test')->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }
        $written = quoted_printable_decode((string) @file_get_contents($log));
        @unlink($log);

        $this->assertStringContainsString('Confirm your new', $written);
        $this->assertStringContainsString('/email-replacement/'.EmailReplacement::sole()->id.'/'.LogMailRedactor::MARK, $written);
        $this->assertStringNotContainsString($this->tokens[0], $written);
    }
}
