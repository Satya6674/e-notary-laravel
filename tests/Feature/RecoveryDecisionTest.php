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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module 5: recovery approval and identity conflicts.
 *
 * Every reason an approval is refused, with its exact message, and proof that a refusal changes
 * nothing; old or invalid requests (mismatched, made on a development copy, about an account made
 * an administrator while archived, from a person since deactivated); rejection; and the exact
 * notices and audit entries of a whole recovery. Mail is the array mailer; synthetic accounts.
 */
class RecoveryDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->person('admin');
    }

    private function person(string $role, array $values = []): User
    {
        $n = ++$this->n;
        $user = User::create($values + ['name' => "Decision {$n}", 'email' => "decision{$n}@example.test", 'mobile' => '95200'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    /** An archived Citizen, and a request from a new account found by its number (as after the emailed code). */
    private function openRequest(): array
    {
        $old = $this->person('public');
        app(AccountDeletionService::class)->delete($this->admin, $old, 'Synthetic deletion for testing.');
        $deletion = AccountDeletion::where('user_id', $old->id)->sole();
        $requester = $this->person('public', ['mobile' => $deletion->mobile]);
        DB::table('users')->where('id', $requester->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
        $recovery = app(AccountRecoveryService::class)->submit($requester->fresh(), $deletion->id);
        $this->assertNotNull($recovery);

        return [$old->fresh(), $deletion, $requester->fresh(), $recovery];
    }

    private function approve(AccountRecoveryRequest $recovery, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.recovery.approve', $recovery), $data + ['identity_checked' => '1']);
    }

    /** A refused approval changed nothing at all. */
    private function assertNothingChanged(User $old, User $requester, AccountRecoveryRequest $recovery, int $auditBefore): void
    {
        $this->assertTrue($old->fresh()->isDeleted(), 'The archived account stays archived.');
        $this->assertFalse($requester->fresh()->isDeleted(), 'The requester keeps their account.');
        $this->assertTrue($recovery->fresh()->isOpen(), 'The request is still open, to be closed with a reason.');
        $this->assertSame(0, DB::table(config('auth.passwords.users.table'))->count(), 'No reset link was made.');
        $this->assertSame(0, AuditLog::where('action', 'account.recovery_approved')->count());
        $this->assertSame($auditBefore, AuditLog::count(), 'No entry written, none lost.');
    }

    public static function refusals(): array
    {
        return [
            'request names a different account than its deletion record' => ['mismatch', 'This request does not match the archived account it names, so it cannot be approved. Close it.'],
            'request made on a development copy, approved on a real site' => ['development', 'This request was made on a development copy of the site, where its checks prove nothing. Close it: the person can ask again here.'],
            'archived account made an administrator while archived' => ['archived_admin', 'The archived account is, or was, an administrator account, so it cannot be recovered here.'],
            'the person who asked was deactivated since' => ['deactivated', 'The person who asked is deactivated. Reactivate their account first if that was a mistake, or close the request.'],
            'the original was restored some other way' => ['restored', 'The original account is no longer archived.'],
            'the person who asked no longer holds what they proved' => ['moved', 'The person who asked no longer holds the email or mobile number they proved, so their proof no longer stands.'],
            'a holder of the identity has open applications' => ['open_work', 'An account holding this identity has open applications. Finish or reassign them first.'],
            'an administrator holds part of the identity' => ['admin_holder', 'An administrator account, current or former, holds this identity, so it cannot be archived here.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_each_unsafe_approval_is_refused_with_its_reason_and_changes_nothing(string $case, string $message): void
    {
        [$old, $deletion, $requester, $recovery] = $this->openRequest();

        match ($case) {
            'mismatch' => (function () use ($recovery) {
                $other = $this->person('public');
                app(AccountDeletionService::class)->delete($this->admin, $other, 'Another deletion.');
                DB::table('account_recovery_requests')->where('id', $recovery->id)->update(['old_user_id' => $other->id]);
            })(),
            'development' => (function () {
                // A real site; the request was recorded on a development copy (the default in tests).
                $this->app['env'] = 'production';
                $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
            })(),
            'archived_admin' => DB::table('users')->where('id', $old->id)->update(['role' => 'admin']),
            'deactivated' => DB::table('users')->where('id', $requester->id)->update(['deactivated_at' => now()]),
            'restored' => DB::table('account_deletions')->where('id', $deletion->id)->update(['restored_at' => now()]),
            'moved' => DB::table('users')->where('id', $requester->id)->update(['mobile' => '9520099999']),
            'open_work' => NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $requester->id, 'document_type' => 'will', 'purpose' => 'Open', 'status' => 'pending']),
            'admin_holder' => $this->person('admin', ['email' => $deletion->email]),
        };

        // 'restored' is simulated on the record only, so the archived row itself stays archived.
        $auditBefore = AuditLog::count();
        $this->approve($recovery->fresh())->assertSessionHas('error', $message);

        if ($case === 'restored') {
            $this->assertTrue($recovery->fresh()->isOpen());
            $this->assertSame(0, AuditLog::where('action', 'account.recovery_approved')->count());

            return;
        }

        $this->assertNothingChanged($old, $requester, $recovery, $auditBefore);
    }

    public function test_an_administrator_cannot_decide_a_request_about_their_own_account(): void
    {
        [, , $requester, $recovery] = $this->openRequest();
        DB::table('users')->where('id', $requester->id)->update(['role' => 'admin']);

        $this->approve($recovery, [], $requester->fresh())->assertSessionHas('error', 'You cannot review a request that involves your own account.');
        $this->assertTrue($recovery->fresh()->isOpen());
    }

    public function test_an_invalid_request_can_be_closed_with_a_reason_and_the_person_is_told(): void
    {
        [$old, , $requester, $recovery] = $this->openRequest();
        DB::table('users')->where('id', $old->id)->update(['role' => 'admin']);

        $this->actingAs($this->admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'The archived account is an administrator account.'])->assertSessionHas('status');

        $closed = $recovery->fresh();
        $this->assertSame(AccountRecoveryRequest::CANCELLED, $closed->status);
        $this->assertNull($closed->open_key);
        $this->assertSame('The archived account is an administrator account.', $closed->decision_reason);
        $this->assertSame(User::CHOICE_NEW, $requester->fresh()->previous_account_choice);
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(1, collect(app('mailer')->getSymfonyTransport()->messages())
            ->filter(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress() === $requester->email && str_contains((string) $m->getOriginalMessage()->getSubject(), 'request was closed'))->count());
    }

    // ---- Rejection

    public function test_a_rejection_keeps_everything_records_the_decision_and_tells_the_person(): void
    {
        [$old, , $requester, $recovery] = $this->openRequest();

        $this->actingAs($this->admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->assertTrue($recovery->fresh()->isOpen());

        $this->actingAs($this->admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'Identity could not be confirmed.'])->assertSessionHas('status');

        $rejected = $recovery->fresh();
        $this->assertSame(AccountRecoveryRequest::REJECTED, $rejected->status);
        $this->assertSame($this->admin->id, (int) $rejected->reviewed_by);
        $this->assertSame('Identity could not be confirmed.', $rejected->decision_reason);
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertFalse($requester->fresh()->isDeleted());
        $this->assertSame(User::CHOICE_NEW, $requester->fresh()->previous_account_choice);

        $entry = AuditLog::where('action', 'account.recovery_rejected')->sole();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($recovery->id, (int) $entry->model_id);

        // Decided once: neither approval nor a second rejection is possible afterwards.
        $this->approve($recovery)->assertSessionHas('error', 'This request has already been decided.');
        $this->assertTrue($old->fresh()->isDeleted());
    }

    // ---- Notices and audit entries of a whole recovery with a conflict

    public function test_a_conflicted_recovery_sends_each_notice_once_to_the_right_address_and_records_each_step(): void
    {
        [$old, $deletion, $requester, $recovery] = $this->openRequest();
        // Someone else registered with the released email meanwhile.
        $holder = $this->person('public', ['email' => $deletion->email]);
        app('mailer')->getSymfonyTransport()->flush();

        $this->actingAs($this->admin)->post(route('admin.recovery.review', $recovery))->assertSessionHas('status');
        $this->approve($recovery)->assertSessionHas('error', 'This request has an identity conflict. Confirm the resolution and give a reason.');
        $this->approve($recovery, ['conflict_confirmed' => '1', 'conflict_reason' => 'Confirmed by video call with both people.'])->assertSessionHas('status');

        $mails = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress().' | '.$m->getOriginalMessage()->getSubject())->sort()->values()->all();

        $this->assertSame(collect([
            $deletion->email.' | Reset your E-Notary password',
            $deletion->email.' | Your E-Notary account has been restored',
            $holder->email.' | Your E-Notary account has been deactivated',
            $requester->email.' | Your E-Notary account recovery was approved',
        ])->sort()->values()->all(), $mails, 'Each notice once, each to a real address; the replaced requester gets no deletion notice.');
        $this->assertFalse(collect($mails)->contains(fn ($m) => str_contains($m, '@deleted.invalid')));

        // The decision and every change it made, by the administrator.
        $approved = AuditLog::where('action', 'account.recovery_approved')->sole();
        $this->assertSame($this->admin->id, $approved->user_id);
        $this->assertTrue($approved->meta['conflict']);
        $this->assertEqualsCanonicalizing([$requester->id, $holder->id], $approved->meta['archived_users']);
        $this->assertSame(1, AuditLog::where('action', 'account.recovery_under_review')->where('user_id', $this->admin->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'admin.user_restored')->where('model_id', $old->id)->where('user_id', $this->admin->id)->count());
        foreach ([$requester, $holder] as $archived) {
            $this->assertSame(1, AuditLog::where('action', 'admin.user_deleted')->where('model_id', $archived->id)->where('user_id', $this->admin->id)->count());
            $this->assertStringContainsString($recovery->reference, AccountDeletion::where('user_id', $archived->id)->sole()->reason);
        }
        $this->assertSame('Confirmed by video call with both people.', $recovery->fresh()->conflict_resolution);

        // The requester's own step was recorded as theirs.
        $this->assertSame($requester->id, AuditLog::where('action', 'account.recovery_requested')->sole()->user_id);

        // No audit entry carries a password, token or code.
        $meta = AuditLog::pluck('meta')->toJson();
        foreach (['token', 'password', 'otp'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $meta);
        }
    }

    public function test_one_person_holding_both_parts_of_the_identity_is_one_conflict_not_two(): void
    {
        // The requester was found by number; someone else holds the email. One other account.
        [$old, $deletion, $requester, $recovery] = $this->openRequest();
        $holder = $this->person('public', ['email' => $deletion->email]);

        $assessment = app(AccountRecoveryService::class)->assess($recovery->fresh());
        $this->assertNull($assessment['blocked']);
        $this->assertTrue($assessment['needs_confirmation']);
        $this->assertEqualsCanonicalizing([$requester->id, $holder->id], $assessment['accounts']->pluck('id')->all());
    }
}
