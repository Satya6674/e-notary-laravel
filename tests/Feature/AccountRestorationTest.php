<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\LogMailRedactor;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Module 3: deletion and plain Admin Restore. A restored account comes back locked: a new
 * password from an emailed link, fresh phone proof, and fresh Notary approval. History stays,
 * a rejected recovery cannot be overridden by accident, and a former Admin's identity is never
 * released. Every account here is synthetic; mail is the array mailer.
 */
class AccountRestorationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Old-demo-pass-1';

    private const REASON = 'Synthetic restoration for testing.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin.one@example.test', '9499900001');
        $this->user('admin', 'admin.two@example.test', '9499900002');
    }

    private function user(string $role, string $email, string $mobile, array $extra = []): User
    {
        $user = User::create([
            'name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);

        $user->forceFill(array_merge(['mobile_verified_at' => now()], $extra))->save();

        return $user->fresh();
    }

    private function archive(User $target): AccountDeletion
    {
        return app(AccountDeletionService::class)->delete($this->admin, $target, 'Synthetic deletion for testing.');
    }

    private function restoreAccount(User $target, array $data = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.users.restore', $target), $data + ['reason' => self::REASON]);
    }

    private function resetToken(string $email): ?string
    {
        foreach (app('mailer')->getSymfonyTransport()->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            if ($message->getTo()[0]->getAddress() === $email && str_contains((string) $message->getSubject(), 'Reset your')
                && preg_match('#/reset-password/([^?"\s]+)#', (string) $message->getHtmlBody(), $found)) {
                return $found[1];
            }
        }

        return null;
    }

    // ---- 1 to 4: a Citizen and a Notary each come back locked

    public function test_a_restored_citizen_needs_a_new_password_and_a_fresh_phone_proof(): void
    {
        $citizen = $this->user('public', 'citizen@example.test', '9499900011');
        $oldHash = $citizen->password;
        $oldRemember = $citizen->remember_token;
        $this->archive($citizen);

        $this->restoreAccount($citizen)->assertRedirect(route('admin.users.deleted'))->assertSessionHas('status');

        $restored = $citizen->fresh();
        $this->assertFalse($restored->isDeleted());
        $this->assertSame('citizen@example.test', $restored->email);
        $this->assertSame('9499900011', $restored->mobile);
        $this->assertTrue($restored->mustResetPassword());
        $this->assertNotSame($oldHash, $restored->password);
        $this->assertFalse(Hash::check(self::PASSWORD, $restored->password), 'the old password no longer works');
        $this->assertTrue($restored->isRetiredPassword(self::PASSWORD), 'and is kept only to refuse its reuse');
        $this->assertNotSame($oldRemember, $restored->remember_token);
        $this->assertNull($restored->mobile_verified_at);
        $this->assertFalse($restored->canRecoverByMobile());

        Auth::logout();
        $this->postJson(route('login.submit'), ['mobile' => 'citizen@example.test', 'password' => self::PASSWORD])->assertStatus(401);

        // The emailed link refuses the old password and accepts a new one.
        $token = $this->resetToken('citizen@example.test');
        $this->assertNotNull($token);
        $this->post(route('password.update'), ['token' => $token, 'email' => 'citizen@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['token' => $token, 'email' => 'citizen@example.test', 'password' => 'New-demo-pass-2', 'password_confirmation' => 'New-demo-pass-2'])->assertRedirect(route('home'));

        // Signed in again, the Citizen area still waits for the phone.
        $this->postJson(route('login.submit'), ['mobile' => 'citizen@example.test', 'password' => 'New-demo-pass-2'])
            ->assertOk()->assertJsonPath('redirect', route('verification.mobile.notice'));
    }

    public function test_a_restored_notary_needs_a_new_password_and_fresh_approval(): void
    {
        $notary = $this->user('notary', 'notary@example.test', '9499900021');
        $this->archive($notary);

        $this->restoreAccount($notary)->assertRedirect(route('admin.users.deleted'));

        $restored = $notary->fresh();
        $this->assertTrue($restored->mustResetPassword());
        $this->assertNull($restored->notary_approved_at);
        $this->assertFalse($restored->isApprovedNotary());
        $this->assertNull($restored->mobile_verified_at);

        $this->post(route('password.update'), ['token' => $this->resetToken('notary@example.test'), 'email' => 'notary@example.test', 'password' => 'New-demo-pass-2', 'password_confirmation' => 'New-demo-pass-2'])->assertRedirect(route('home'));

        Auth::logout();
        $this->postJson(route('login.submit'), ['mobile' => 'notary@example.test', 'password' => 'New-demo-pass-2'])
            ->assertOk()->assertJsonPath('redirect', route('notary.pending'));

        // A password sign-in clears any step-up window in that session (decision 3); this test shares one
        // session between two people, so the Admin confirms again before approving.
        $this->actingAs($this->admin)->withSession([\App\Support\AdminReauth::SESSION_KEY => time()])->post(route('admin.notaries.approve', $restored))->assertRedirect();
        $this->actingAs($restored->fresh())->get(route('notary.dashboard'))->assertOk();
    }

    // ---- 5 and 6: history and reasons stay

    public function test_history_and_reasons_are_kept_through_a_restore(): void
    {
        $citizen = $this->user('public', 'history@example.test', '9499900031');
        $deletion = $this->archive($citizen);

        $this->actingAs($this->admin)->delete(route('admin.users.delete', $citizen->fresh()), ['confirm' => '1'])->assertSessionHasErrors('reason');
        $this->restoreAccount($citizen, ['reason' => 'too short'])->assertSessionHasErrors('reason');
        $this->restoreAccount($citizen, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($citizen->fresh()->isDeleted(), 'no restore without a proper reason');

        $this->restoreAccount($citizen);

        $record = $deletion->fresh();
        $this->assertSame('Synthetic deletion for testing.', $record->reason);
        $this->assertSame($this->admin->id, $record->restored_by);
        $this->assertNotNull($record->deleted_at);
        $this->assertSame(1, AuditLog::where('action', 'admin.user_deleted')->where('model_id', $citizen->id)->count());

        $restored = AuditLog::where('action', 'admin.user_restored')->where('model_id', $citizen->id)->sole();
        $this->assertSame(self::REASON, $restored->meta['reason']);
        $this->assertSame('admin', $restored->meta['via']);
        $this->assertTrue($restored->meta['fresh_proof_required']);

        $this->actingAs($this->admin)->get(route('admin.users.deleted'))->assertOk()->assertSee($record->reference);
    }

    // ---- 7: a rejected recovery is overridden only on purpose

    public function test_a_restore_after_a_rejected_recovery_needs_explicit_confirmation(): void
    {
        $citizen = $this->user('public', 'rejected@example.test', '9499900041');
        $deletion = $this->archive($citizen);
        $requester = $this->user('public', 'someone.new@example.test', '9499900042');

        AccountRecoveryRequest::create([
            'reference' => 'REC-DEMO-0001', 'account_deletion_id' => $deletion->id, 'old_user_id' => $citizen->id,
            'requester_id' => $requester->id, 'applicant_name' => 'Synthetic', 'verified_email' => 'someone.new@example.test',
            'match_type' => 'email', 'email_proof' => AccountRecoveryRequest::PROOF_EMAIL_CODE, 'mobile_proof' => AccountRecoveryRequest::PROOF_NONE,
            'environment' => AccountRecoveryRequest::ENV_LOCAL, 'status' => AccountRecoveryRequest::REJECTED, 'open_key' => null,
            'reviewed_by' => $this->admin->id, 'reviewed_at' => now(), 'decision_reason' => 'Synthetic rejection.',
        ]);

        $this->actingAs($this->admin)->get(route('admin.users.deleted'))->assertOk()
            ->assertSee('A recovery request for this account was rejected. I have reviewed that decision.');

        $this->restoreAccount($citizen)->assertSessionHas('error', 'A recovery request for this account was rejected. Confirm that you have reviewed that decision before restoring it.');
        $this->assertTrue($citizen->fresh()->isDeleted());
        $this->assertSame(0, DB::table('password_resets')->count());

        $this->restoreAccount($citizen, ['rejection_reviewed' => '1'])->assertSessionHas('status');
        $this->assertFalse($citizen->fresh()->isDeleted());
        $this->assertTrue(AuditLog::where('action', 'admin.user_restored')->sole()->meta['after_rejected_recovery']);
    }

    // ---- 8: a former Admin's identity is never released

    public function test_a_demoted_admin_keeps_their_identity_when_deleted(): void
    {
        $former = $this->user('admin', 'former.admin@example.test', '9499900051');

        // Demoted through the ordinary edit form first, as the earlier audit proved possible.
        $this->actingAs($this->admin)->put(route('admin.users.update', $former), [
            'name' => $former->name, 'email' => $former->email, 'mobile' => $former->mobile, 'role' => 'public',
        ])->assertRedirect();
        $this->assertSame('public', $former->fresh()->role);

        $deletion = $this->archive($former->fresh());

        $this->assertNull($deletion->identity_released_at);
        $this->assertSame('former.admin@example.test', $former->fresh()->email);
        $this->assertSame('9499900051', $former->fresh()->mobile);
        $this->assertTrue(AuditLog::where('action', 'admin.user_deleted')->where('model_id', $former->id)->sole()->meta['former_admin']);

        // Nobody can register with it, and it is never offered for recovery.
        Auth::logout();
        Cache::flush();
        $this->postJson(route('register.submit'), ['name' => 'X', 'email' => 'former.admin@example.test', 'mobile' => '9499900059', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'public', 'accept_terms' => '1'])->assertStatus(422);
        Cache::flush();
        $this->postJson(route('register.submit'), ['name' => 'X', 'email' => 'other@example.test', 'mobile' => '9499900051', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'public', 'accept_terms' => '1'])->assertStatus(422);
        $this->assertNull(app(AccountRecoveryService::class)->detect($this->user('public', 'probe@example.test', '9499900058')));
    }

    public function test_a_never_admin_identity_is_still_released(): void
    {
        $notary = $this->user('notary', 'only.notary@example.test', '9499900061');

        // A role change that never involved Admin does not count.
        $this->actingAs($this->admin)->put(route('admin.users.update', $notary), [
            'name' => $notary->name, 'email' => $notary->email, 'mobile' => $notary->mobile, 'role' => 'public',
        ])->assertRedirect();

        $this->assertNotNull($this->archive($notary->fresh())->identity_released_at);
        $this->assertStringEndsWith('@deleted.invalid', $notary->fresh()->email);
    }

    // ---- Concurrency and tokens

    public function test_a_second_restore_finds_the_account_already_restored(): void
    {
        $citizen = $this->user('public', 'twice@example.test', '9499900071');
        $this->archive($citizen);

        $this->restoreAccount($citizen)->assertSessionHas('status');
        $this->restoreAccount($citizen)->assertSessionHas('error', 'This account is not deleted.');

        $this->assertSame(1, DB::table('password_resets')->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.user_restored')->count());
        $this->assertSame(1, AccountDeletion::whereNotNull('restored_at')->count());
    }

    public function test_the_reset_token_never_appears_in_the_page_the_queue_or_a_log(): void
    {
        $citizen = $this->user('public', 'token@example.test', '9499900081');
        $this->archive($citizen);

        // Queued for a worker, as on the real site: the job is encrypted.
        config(['queue.default' => 'database']);
        $response = $this->restoreAccount($citizen);

        $this->assertStringNotContainsString('reset-password', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('reset-password', (string) session('status'));

        // Only the reset email is encrypted; the notices beside it carry ids, not secrets.
        $payloads = DB::table('jobs')->pluck('payload');
        $this->assertTrue($payloads->every(fn ($payload) => ! str_contains($payload, 's:5:"token"')));
        $reset = $payloads->first(function ($payload) {
            $command = json_decode($payload, true)['data']['command'];

            return ! str_starts_with($command, 'O:') && str_contains(Crypt::decryptString($command), 'ResetPasswordNotification');
        });
        $this->assertNotNull($reset, 'the reset email is queued');
        $this->assertStringNotContainsString('s:5:"token"', $reset);
        $this->assertStringNotContainsString('token@example.test', $reset);
    }

    public function test_the_log_mailer_never_writes_a_usable_reset_link(): void
    {
        $email = (new Email())->to('demo@example.test')
            ->html('<a href="http://127.0.0.1:8000/reset-password/0123456789abcdef0123456789abcdef?email=demo%40example.test">Reset</a>')
            ->text('Open http://127.0.0.1:8000/reset-password/0123456789abcdef0123456789abcdef?email=demo%40example.test');

        $redacted = LogMailRedactor::redacted($email);

        foreach ([$redacted->getHtmlBody(), $redacted->getTextBody()] as $body) {
            $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef', $body);
            $this->assertStringContainsString('/reset-password/[removed-in-log-mode]', $body);
        }

        // Wired to the log mailer only: with it, a real restore writes no token to the log file.
        $log = storage_path('framework/testing/mail-log-'.getmypid().'.log');
        @unlink($log);
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'restore-test', 'logging.channels.restore-test' => ['driver' => 'single', 'path' => $log]]);

        $citizen = $this->user('public', 'logged@example.test', '9499900091');
        $this->archive($citizen);
        $this->restoreAccount($citizen);

        $written = (string) @file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('[removed-in-log-mode]', $written);
        $this->assertDoesNotMatchRegularExpression('#/reset-password/[0-9a-f]{20,}#', $written);
    }
}
