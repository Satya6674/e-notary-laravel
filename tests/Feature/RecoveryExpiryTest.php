<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A recovery request can be approved for 30 days (owner decision, 2026-11-04): up to the second
 * before, never from the moment itself. An expired request can be closed with a reason, and the
 * person may then ask again, which needs a fresh code. Times use the application timezone.
 */
class RecoveryExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** The moment every request here was made: a fixed instant, in the app's timezone (UTC). */
    private const MADE = '2026-10-01 10:00:00';

    private const APPROVED = 'Recovery approved. The original account was restored and needs a new password.';

    private const TOO_OLD = 'This request is more than 30 days old, so its proof is too old to rely on. Close it: the person can then ask again with fresh proof.';

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
        $user = User::create($values + ['name' => "Expiry {$n}", 'email' => "expiry{$n}@example.test", 'mobile' => '95400'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->forceFill(['mobile_verified_at' => now(), 'email_verification_method' => 'signed_link', 'mobile_verification_method' => User::METHOD_SMS, 'mobile_verification_status' => User::STATUS_SMS_VERIFIED])->save();

        return $user->fresh();
    }

    /** A request made at MADE, by a new account found by the archived account's number. */
    private function request(): array
    {
        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone')));

        $old = $this->person('public');
        app(AccountDeletionService::class)->delete($this->admin, $old, 'Synthetic deletion for testing.');
        $deletion = AccountDeletion::where('user_id', $old->id)->sole();
        $requester = $this->person('public', ['mobile' => $deletion->mobile]);
        DB::table('users')->where('id', $requester->id)->update(['previous_account_choice' => User::CHOICE_PENDING]);
        $recovery = app(AccountRecoveryService::class)->submit($requester->fresh(), $deletion->id);

        $this->travelBack();

        return [$old, $deletion, $requester, $recovery];
    }

    private function approve(AccountRecoveryRequest $recovery)
    {
        return $this->actingAs($this->admin)->post(route('admin.recovery.approve', $recovery), ['identity_checked' => '1']);
    }

    public static function boundary(): array
    {
        return [
            '29 days, 23:59:59 old' => [-1, true],
            'exactly 30 days old' => [0, false],
            '30 days and 1 second old' => [1, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundary')]
    public function test_a_request_can_be_approved_until_exactly_30_days(int $offsetSeconds, bool $approvable): void
    {
        [$old, , , $recovery] = $this->request();
        $this->assertSame('2026-10-31 10:00:00', $recovery->expiresAt()->format('Y-m-d H:i:s'));

        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone'))->addDays(30)->addSeconds($offsetSeconds));

        $this->assertSame(! $approvable, $recovery->fresh()->isExpired());

        if ($approvable) {
            $this->approve($recovery)->assertSessionHas('status', self::APPROVED);
            $this->assertFalse($old->fresh()->isDeleted());

            return;
        }

        $this->approve($recovery)->assertSessionHas('error', self::TOO_OLD);
        $this->assertTrue($old->fresh()->isDeleted(), 'Nothing restored.');
        $this->assertTrue($recovery->fresh()->isOpen(), 'Still open, to be closed with a reason.');
    }

    public function test_the_boundary_is_the_same_instant_whatever_timezone_the_clock_is_read_in(): void
    {
        [, , , $recovery] = $this->request();

        // 30 days later is 15:30 in India (UTC+5:30): one second before, then the instant itself.
        // (The clock is set as that instant in UTC: a test clock carrying another timezone makes
        // Carbon read stored times in that zone, which the application itself never does.)
        $this->travelTo(Carbon::parse('2026-10-31 15:29:59', 'Asia/Kolkata')->utc());
        $this->assertFalse($recovery->fresh()->isExpired());
        $this->travelTo(Carbon::parse('2026-10-31 15:30:00', 'Asia/Kolkata')->utc());
        $this->assertTrue($recovery->fresh()->isExpired());

        // The administrator sees the same moment, in Indian time.
        $this->actingAs($this->admin)->get(route('admin.recovery.show', $recovery))->assertOk()->assertSee('31 Oct 2026, 03:30 PM IST', false);
    }

    public function test_an_expired_request_is_closed_with_a_reason_and_the_person_may_ask_again_with_fresh_proof(): void
    {
        [$old, $deletion, $requester, $recovery] = $this->request();
        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone'))->addDays(31));

        // The person sees why nothing is happening.
        $this->actingAs($requester->fresh())->get(route('account.recovery.status'))->assertOk()->assertSee('This request is more than 30 days old');

        $this->actingAs($this->admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'Older than 30 days; ask again.'])->assertSessionHas('status');

        $closed = $recovery->fresh();
        $this->assertSame(AccountRecoveryRequest::EXPIRED, $closed->status);
        $this->assertNull($closed->open_key);
        $this->assertSame('Older than 30 days; ask again.', $closed->decision_reason);
        $this->assertSame(1, AuditLog::where('action', 'account.recovery_expired')->where('user_id', $this->admin->id)->count());
        $this->assertTrue($old->fresh()->isDeleted());
        $this->assertSame(User::CHOICE_CHECK, $requester->fresh()->previous_account_choice, 'Asked again at the next visit.');

        // Asking again needs a fresh code: confirming without one sent in this session opens nothing.
        $this->actingAs($requester->fresh())->get(route('account.previous'))->assertOk();
        $this->actingAs($requester->fresh())->post(route('account.previous.confirm'), ['otp' => '123456']);
        $this->assertSame(1, AccountRecoveryRequest::count());

        // With a fresh code to the previous address, a new request is opened, and it can be approved.
        $this->actingAs($requester->fresh())->post(route('account.previous.send'))->assertSessionHas('status');
        $mail = collect(app('mailer')->getSymfonyTransport()->messages())->last(fn ($m) => str_contains((string) $m->getOriginalMessage()->getSubject(), 'recovery code'));
        $this->assertSame($deletion->email, $mail->getEnvelope()->getRecipients()[0]->getAddress());
        preg_match('/recovery code is (\d{6})/', (string) $mail->getOriginalMessage()->getTextBody(), $code);
        $this->actingAs($requester->fresh())->post(route('account.previous.confirm'), ['otp' => $code[1]])->assertRedirect(route('account.recovery.status'));

        $fresh = AccountRecoveryRequest::where('status', AccountRecoveryRequest::PENDING)->sole();
        $this->assertNotSame($recovery->id, $fresh->id);
        $this->assertFalse($fresh->isExpired());
        $this->approve($fresh)->assertSessionHas('status', self::APPROVED);
    }

    public function test_rejecting_an_expired_request_is_still_a_rejection(): void
    {
        [, , $requester, $recovery] = $this->request();
        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone'))->addDays(40));

        $this->actingAs($this->admin)->post(route('admin.recovery.reject', $recovery), ['reason' => 'Identity could not be confirmed.'])->assertSessionHas('status');

        $this->assertSame(AccountRecoveryRequest::REJECTED, $recovery->fresh()->status);
        $this->assertSame(User::CHOICE_NEW, $requester->fresh()->previous_account_choice, 'A rejection stays final.');
    }

    public function test_closing_a_request_that_has_not_expired_is_an_ordinary_cancellation(): void
    {
        [, , $requester, $recovery] = $this->request();
        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone'))->addDays(10));

        $this->actingAs($this->admin)->post(route('admin.recovery.cancel', $recovery), ['reason' => 'Person asked us to stop.'])->assertSessionHas('status');

        $this->assertSame(AccountRecoveryRequest::CANCELLED, $recovery->fresh()->status);
        $this->assertSame(User::CHOICE_NEW, $requester->fresh()->previous_account_choice);
    }

    /** Audit B-10: the "Expired" filter was offered but ignored, so it listed every request. */
    public function test_the_expired_filter_lists_only_expired_requests(): void
    {
        [, , , $expired] = $this->request();
        $this->travelTo(Carbon::parse(self::MADE, config('app.timezone'))->addDays(31));
        $this->actingAs($this->admin)->post(route('admin.recovery.cancel', $expired), ['reason' => 'Older than 30 days; ask again.']);
        $this->travelBack();
        [, , , $open] = $this->request();

        $listed = $this->actingAs($this->admin)->get(route('admin.recovery.index', ['status' => AccountRecoveryRequest::EXPIRED]))->assertOk()->viewData('requests');

        $this->assertSame([$expired->id], collect($listed->items())->pluck('id')->all());
        $this->assertTrue($open->fresh()->isOpen());
    }
}
