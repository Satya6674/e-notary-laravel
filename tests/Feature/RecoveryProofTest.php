<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Module 4: account recovery and re-registration.
 *
 * - A phone number alone never authorizes a recovery: the code always goes to the previous
 *   account's own email address, and a request without that proof cannot be approved.
 * - A code proves one mailbox, so it opens a request for that one archived account only.
 * - Approval sends a secure reset link: to the restored address only, hashed at rest, encrypted
 *   while queued, single-use, and replaced (not duplicated) by a retry.
 * - Re-registration after an identity is released is safe, nothing carries over from the old
 *   account, and it is refused once the identity is returned.
 * - History is kept: no deletion record or audit entry is lost along the way.
 *
 * Mail is the array mailer; SMS is faked; every account is synthetic.
 */
class RecoveryProofTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'previous.owner@example.test';

    private const MOBILE = '9000000301';

    private const OLD_PASSWORD = 'Old-demo-pass-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->person('admin', ['email' => 'recovery.admin@example.test', 'mobile' => '9000000390']);
    }

    private function person(string $role = 'public', array $values = []): User
    {
        $user = User::factory()->create(array_intersect_key($values, array_flip(['email', 'mobile', 'name'])) + [
            'role' => $role, 'password' => Hash::make(self::OLD_PASSWORD), 'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ]);
        $user->forceFill(['email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    private function archived(): User
    {
        $user = $this->person('public', ['email' => self::EMAIL, 'mobile' => self::MOBILE, 'name' => 'Previous Owner']);
        app(AccountDeletionService::class)->delete($this->admin, $user, 'Synthetic deletion for testing.');

        return $user->fresh();
    }

    private function registered(string $email, string $mobile, string $method = User::METHOD_SMS): User
    {
        Auth::logout();
        $this->postJson(route('register.submit'), ['name' => 'New Name', 'email' => $email, 'mobile' => $mobile, 'password' => 'New-account-password1', 'password_confirmation' => 'New-account-password1', 'role' => 'public', 'accept_terms' => '1'])->assertOk();
        Auth::logout();

        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verified_at' => now(), 'mobile_verification_method' => $method,
            'mobile_verification_status' => $method === User::METHOD_SMS ? User::STATUS_SMS_VERIFIED : User::STATUS_SIMULATED])->save();

        return $user->fresh();
    }

    private function sent(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => (string) $m->getOriginalMessage()->getSubject(),
                'text' => (string) $m->getOriginalMessage()->getTextBody(), 'html' => (string) $m->getOriginalMessage()->getHtmlBody()])
            ->values()->all();
    }

    private function codeSentTo(string $address): ?string
    {
        $mail = collect($this->sent())->last(fn ($m) => $m['to'] === $address && str_contains($m['subject'], 'recovery code'));

        return $mail && preg_match('/recovery code is (\d{6})/', $mail['text'], $found) ? $found[1] : null;
    }

    private function resetTokensSentTo(string $address): array
    {
        return collect($this->sent())->filter(fn ($m) => $m['to'] === $address && str_contains($m['subject'], 'Reset your'))
            ->map(fn ($m) => preg_match('#/reset-password/([^?"\s]+)#', $m['html'], $found) ? $found[1] : null)->filter()->values()->all();
    }

    private function smsOn(?string &$code = null): void
    {
        config(['services.msg91.auth_key' => 'test-key', 'services.msg91.template_id' => 'test-template', 'services.msg91.preflight' => false]);
        Http::fake(function ($request) use (&$code) {
            $code = $request->data()['otp'] ?? null;

            return Http::response(['type' => 'success', 'request_id' => 'abcdef123456']);
        });
    }

    /** Through the real routes: a code to the previous address, then the request. */
    private function requestRecovery(User $user, string $readFrom = self::EMAIL): AccountRecoveryRequest
    {
        $this->actingAs($user)->get(route('account.previous'))->assertOk();
        $this->actingAs($user)->post(route('account.previous.send'))->assertSessionHas('status');
        $this->actingAs($user)->post(route('account.previous.confirm'), ['otp' => $this->codeSentTo($readFrom)])->assertRedirect(route('account.recovery.status'));

        return AccountRecoveryRequest::where('requester_id', $user->id)->sole();
    }

    private function approve(AccountRecoveryRequest $recovery, array $data = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.recovery.approve', $recovery), $data + ['identity_checked' => '1']);
    }

    // ---- A phone number alone never authorizes a recovery

    public function test_a_phone_match_sends_the_code_to_the_previous_address_and_never_by_sms(): void
    {
        $this->archived();
        $this->smsOn($sms);
        $holder = $this->registered('number.holder@example.test', self::MOBILE);

        $this->actingAs($holder)->get(route('account.previous.code'))->assertOk()
            ->assertSee('This is the email address of your previous account.')
            ->assertSee('pr***@example.test', false)->assertDontSee(self::EMAIL);
        $this->actingAs($holder)->post(route('account.previous.send'))->assertSessionHas('status');

        $this->assertNull($sms, 'No SMS code for a recovery.');
        $this->assertNotNull($this->codeSentTo(self::EMAIL), 'The code went to the previous account.');
        $this->assertNull($this->codeSentTo('number.holder@example.test'));
        $this->assertSame('previous_account_address', AuditLog::where('action', 'account.recovery_code_requested')->sole()->meta['sent_to']);
    }

    public function test_someone_who_only_holds_the_number_cannot_open_a_request(): void
    {
        $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->actingAs($holder)->post(route('account.previous.send'));
        $real = $this->codeSentTo(self::EMAIL);

        // The holder cannot read the previous mailbox, so every guess fails.
        foreach (['000000', '123456', '999999'] as $guess) {
            if ($guess === $real) {
                continue;
            }
            $this->actingAs($holder)->post(route('account.previous.confirm'), ['otp' => $guess])->assertSessionHasErrors('otp');
        }

        $this->assertSame(0, AccountRecoveryRequest::count());
        $this->assertTrue(User::where('email', 'number.holder@example.test')->sole()->previous_account_choice === User::CHOICE_PENDING);
    }

    public function test_an_sms_code_for_the_number_does_not_open_a_request(): void
    {
        $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->smsOn($sms);

        // An SMS code issued for the recovery purpose by any other path proves only the phone.
        app(\App\Services\OtpService::class)->issue(self::MOBILE, '127.0.0.1', \App\Services\OtpService::PURPOSE_RECOVERY, $holder->id);
        $this->assertNotNull($sms);

        $this->actingAs($holder)->post(route('account.previous.confirm'), ['otp' => $sms]);

        $this->assertSame(0, AccountRecoveryRequest::count(), 'A phone code never opens a request.');
    }

    public function test_a_phone_only_request_made_before_this_fix_cannot_be_approved(): void
    {
        $old = $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $deletion = AccountDeletion::sole();

        // As the old flow recorded it: a mobile match with an SMS code and no email proof.
        $recovery = AccountRecoveryRequest::create(['reference' => 'REC-PHONE-ONLY', 'account_deletion_id' => $deletion->id, 'old_user_id' => $old->id,
            'requester_id' => $holder->id, 'applicant_name' => 'Holder', 'verified_email' => $holder->email, 'verified_mobile' => self::MOBILE,
            'match_type' => 'mobile', 'email_proof' => AccountRecoveryRequest::PROOF_NONE, 'mobile_proof' => AccountRecoveryRequest::PROOF_GENUINE_SMS,
            'environment' => 'production', 'status' => AccountRecoveryRequest::PENDING, 'open_key' => $deletion->id]);

        $this->approve($recovery)->assertSessionHas('error', 'This request was made with phone proof only, which is not enough. Close it: the person can ask again and prove the previous account\'s email address.');

        $this->assertTrue($old->fresh()->isDeleted(), 'Nothing was restored.');
        $this->assertFalse($holder->fresh()->isDeleted());
        $this->assertTrue($recovery->fresh()->isOpen());
        $this->assertSame([], $this->resetTokensSentTo(self::EMAIL));

        // It can still be closed, with the person told why.
        $this->actingAs($this->admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'Phone proof alone is not enough.'])->assertSessionHas('status');
        $this->assertFalse($recovery->fresh()->isOpen());
    }

    public function test_a_code_opens_a_request_only_for_the_account_it_was_sent_for(): void
    {
        $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->actingAs($holder)->post(route('account.previous.send'));
        $code = $this->codeSentTo(self::EMAIL);

        // Before the code is used, another archived account starts matching the same number,
        // and is now the one found. The code proved the first mailbox, not this one.
        $second = $this->person('public', ['email' => 'second.owner@example.test', 'mobile' => '9000000302']);
        app(AccountDeletionService::class)->delete($this->admin, $second, 'Synthetic second deletion.');
        AccountDeletion::where('user_id', $second->id)->update(['mobile' => self::MOBILE]);
        DB::table('users')->where('id', $second->id)->update(['mobile_verified_at' => now()]);
        $this->assertSame($second->id, app(AccountRecoveryService::class)->detect($holder->fresh())->user_id);

        $this->actingAs($holder)->post(route('account.previous.confirm'), ['otp' => $code])->assertRedirect(route('account.previous'));

        $this->assertSame(0, AccountRecoveryRequest::count());
    }

    public function test_a_confirm_without_a_code_sent_in_this_session_opens_nothing(): void
    {
        $this->archived();
        $new = $this->registered(self::EMAIL, '9000000303');

        // A code exists (issued for this number) but was never sent through this session.
        $code = null;
        app(\App\Services\OtpService::class)->issueRecoveryEmail('9000000303', '127.0.0.1', function (string $issued) use (&$code) {
            $code = $issued;

            return true;
        });

        $this->actingAs($new)->post(route('account.previous.confirm'), ['otp' => $code])->assertRedirect(route('account.previous'));
        $this->assertSame(0, AccountRecoveryRequest::count());
    }

    public function test_both_proofs_keep_the_phone_proven_and_email_alone_does_not(): void
    {
        // Found by number, proved the previous mailbox, and the same number by genuine SMS.
        $old = $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->approve($this->requestRecovery($holder))->assertSessionHas('status');
        $this->assertNotNull($old->fresh()->mobile_verified_at, 'Both proofs: the phone stays proven.');

        // Found by email, with a phone proven only by mock SMS: the number must be proven again.
        $other = $this->person('public', ['email' => 'other.owner@example.test', 'mobile' => '9000000304']);
        app(AccountDeletionService::class)->delete($this->admin, $other, 'Synthetic deletion.');
        $again = $this->registered('other.owner@example.test', '9000000304', User::METHOD_MOCK_SMS);
        $this->approve($this->requestRecovery($again, 'other.owner@example.test'))->assertSessionHas('status');
        $this->assertNull($other->fresh()->mobile_verified_at);
        $this->assertFalse($other->fresh()->canRecoverByMobile());
    }

    // ---- Approval sends a secure reset link

    public function test_approval_sends_one_reset_link_to_the_restored_address_only(): void
    {
        $old = $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->approve($this->requestRecovery($holder))->assertSessionHas('status');

        $tokens = $this->resetTokensSentTo(self::EMAIL);
        $this->assertCount(1, $tokens);
        $this->assertSame([], $this->resetTokensSentTo('number.holder@example.test'), 'Never to the requester.');

        // Hashed at rest: the table holds no usable link.
        $row = DB::table(config('auth.passwords.users.table'))->where('email', self::EMAIL)->sole();
        $this->assertNotSame($tokens[0], $row->token);
        $this->assertTrue(Hash::check($tokens[0], $row->token));

        // Locked until the link is used, and the old password is refused.
        $this->assertTrue($old->fresh()->mustResetPassword());
        $this->postJson(route('login.submit'), ['mobile' => self::EMAIL, 'password' => self::OLD_PASSWORD])->assertStatus(401);

        $this->post(route('password.update'), ['token' => $tokens[0], 'email' => self::EMAIL, 'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])->assertRedirect(route('home'));
        $this->assertFalse($old->fresh()->mustResetPassword());

        // Single use.
        Auth::logout();
        $this->post(route('password.update'), ['token' => $tokens[0], 'email' => self::EMAIL, 'password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'])->assertSessionHasErrors();
        $this->assertTrue(Hash::check('Brand-new-pass-1', $old->fresh()->password));
    }

    public function test_the_queued_reset_link_is_encrypted(): void
    {
        $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $recovery = $this->requestRecovery($holder);

        config(['queue.default' => 'database']);
        $this->approve($recovery)->assertSessionHas('status');

        $commands = DB::table('jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true)['data']['command'] ?? '');
        $reset = $commands->first(fn ($c) => ! str_starts_with($c, 'O:') && str_contains(Crypt::decryptString($c), 'ResetPasswordNotification'));

        $this->assertNotNull($reset, 'The reset email is queued, encrypted.');
        $this->assertFalse($commands->contains(fn ($c) => str_starts_with($c, 'O:') && str_contains($c, 'ResetPasswordNotification')), 'Never in plain form.');
    }

    public function test_a_reset_retry_replaces_the_link_and_the_old_one_stops_working(): void
    {
        $old = $this->archived();
        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $recovery = $this->requestRecovery($holder);
        $this->approve($recovery);
        [$first] = $this->resetTokensSentTo(self::EMAIL);

        // As if the first one failed to send.
        \App\Models\MessageDelivery::where('notification_id', $recovery->fresh()->reset_notification_id)->update(['status' => \App\Models\MessageDelivery::FAILED]);
        $this->actingAs($this->admin)->post(route('admin.recovery.retry', [$recovery, 'reset']))->assertSessionHas('status');

        $tokens = $this->resetTokensSentTo(self::EMAIL);
        $this->assertCount(2, $tokens);
        $this->assertFalse(Password::broker()->tokenExists($old->fresh(), $first));
        $this->assertTrue(Password::broker()->tokenExists($old->fresh(), $tokens[1]));
        $this->assertSame(1, DB::table(config('auth.passwords.users.table'))->where('email', self::EMAIL)->count());
    }

    // ---- Re-registration after identity release

    public function test_re_registration_after_release_starts_clean(): void
    {
        $old = $this->person('public', ['email' => self::EMAIL, 'mobile' => self::MOBILE]);
        // State the old account had: a live reset token and an unused code.
        $oldToken = Password::broker()->createToken($old);
        DB::table('otp_verifications')->insert(['mobile' => self::MOBILE, 'otp' => 'x|'.Hash::make('111111'), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);

        app(AccountDeletionService::class)->delete($this->admin, $old, 'Synthetic deletion for testing.');
        $this->assertNotNull(AccountDeletion::sole()->identity_released_at);

        $new = $this->registered(self::EMAIL, self::MOBILE);

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(0, DB::table('otp_verifications')->where('mobile', self::MOBILE)->where('otp', 'like', 'x|%')->count(), 'The old code went with the old account.');
        $this->assertFalse(Password::broker()->tokenExists($new, $oldToken), 'The old reset link does not open the new account.');
        $this->post(route('password.update'), ['token' => $oldToken, 'email' => self::EMAIL, 'password' => 'Takeover-pass-1', 'password_confirmation' => 'Takeover-pass-1'])->assertSessionHasErrors();
        $this->assertFalse(Hash::check('Takeover-pass-1', $new->fresh()->password));
        $this->assertSame(User::CHOICE_CHECK, $new->previous_account_choice, 'The person is asked about the previous account.');
    }

    public function test_once_recovered_the_identity_cannot_be_registered_again(): void
    {
        $this->archived();
        $new = $this->registered(self::EMAIL, '9000000305');
        $this->approve($this->requestRecovery($new))->assertSessionHas('status');

        Auth::logout();
        $this->postJson(route('register.submit'), ['name' => 'Copy', 'email' => self::EMAIL, 'mobile' => '9000000306', 'password' => 'Copy-password-1', 'password_confirmation' => 'Copy-password-1', 'role' => 'public', 'accept_terms' => '1'])->assertStatus(422);
        $this->postJson(route('register.submit'), ['name' => 'Copy', 'email' => 'copy@example.test', 'mobile' => self::MOBILE, 'password' => 'Copy-password-1', 'password_confirmation' => 'Copy-password-1', 'role' => 'public', 'accept_terms' => '1'])->assertStatus(422);

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [self::EMAIL])->count());
        $this->assertSame(1, User::where('mobile', self::MOBILE)->count());
    }

    public function test_the_whole_cycle_keeps_every_record(): void
    {
        $old = $this->archived();
        $application = NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $old->id, 'document_type' => 'will', 'purpose' => 'Kept', 'status' => 'completed']);
        $before = AuditLog::pluck('id')->all();

        $holder = $this->registered('number.holder@example.test', self::MOBILE);
        $this->approve($this->requestRecovery($holder))->assertSessionHas('status');

        // Nothing removed: every earlier audit entry is still there, unchanged in number.
        $this->assertSame([], array_values(array_diff($before, AuditLog::pluck('id')->all())));
        $this->assertGreaterThan(count($before), AuditLog::count());

        // Both deletion records stay: the original (now restored) and the replaced new account.
        $this->assertNotNull(AccountDeletion::where('user_id', $old->id)->sole()->restored_at);
        $this->assertTrue($holder->fresh()->isDeleted());
        $this->assertNotNull(AccountDeletion::where('user_id', $holder->id)->sole());

        // The old account's own history is its own again.
        $this->assertSame($old->id, (int) $application->fresh()->user_id);
        $this->assertSame(self::EMAIL, $old->fresh()->email);
        foreach (['account.recovery_requested', 'account.recovery_approved', 'admin.user_restored', 'admin.user_deleted'] as $action) {
            $this->assertGreaterThan(0, AuditLog::where('action', $action)->count(), $action);
        }
    }
}
