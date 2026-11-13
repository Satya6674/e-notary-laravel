<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\EmailVerificationController as Verify;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asking for another verification code. Messages go to the in-memory array mailer, which returns a
 * real result, so "sent" is asserted only where the transport accepted the message. Nothing leaves.
 */
class VerificationResendTest extends TestCase
{
    use RefreshDatabase;

    private function citizen(array $values = []): User
    {
        return User::factory()->create($values + ['role' => 'public', 'email' => 'citizen@example.test', 'email_verified_at' => null]);
    }

    private function resend(User $user)
    {
        return $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'));
    }

    private function sentCount(): int
    {
        return count(app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_citizen_can_request_another_code(): void
    {
        $user = $this->citizen();

        $this->resend($user)->assertSessionHas('status', Verify::CODE_SENT);

        $this->assertSame(1, $this->sentCount());
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('user_id', $user->id)->value('status'));
    }

    public function test_repeated_clicks_send_only_one_message(): void
    {
        $user = $this->citizen();

        $this->resend($user);
        $this->resend($user)->assertSessionHas('error', Verify::RESEND_WAIT);
        $this->resend($user)->assertSessionHas('error', Verify::RESEND_WAIT);

        $this->assertSame(1, $this->sentCount());
    }

    public function test_another_code_is_allowed_after_the_cooldown(): void
    {
        $user = $this->citizen();

        $this->resend($user);
        $this->travel(61)->seconds();
        $this->resend($user)->assertSessionHas('status', Verify::CODE_SENT);

        $this->assertSame(2, $this->sentCount());
    }

    public function test_a_message_still_waiting_is_not_sent_again(): void
    {
        Queue::fake();
        $user = $this->citizen();
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => 'queued', 'user_id' => $user->id]);

        $this->resend($user)->assertSessionHas('error', Verify::RESEND_WAIT);

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->sentCount());
    }

    /** Audit B-7: with no queue worker, a waiting message blocked every new code for ever. */
    public function test_a_message_waiting_for_a_long_time_no_longer_blocks_a_new_code(): void
    {
        $user = $this->citizen();
        $stuck = MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => 'queued', 'user_id' => $user->id]);
        $stuck->forceFill(['created_at' => now()->subMinutes(16), 'updated_at' => now()->subMinutes(16)])->save();

        $this->resend($user)->assertSessionHas('status', Verify::CODE_SENT);

        $this->assertSame(1, $this->sentCount());
    }

    public function test_an_address_outside_the_allowlist_gets_the_plain_failure_and_no_code(): void
    {
        Notification::fake();
        config(['services.outbound.mail' => 'approved@example.test']);
        $user = $this->citizen();

        $this->resend($user)->assertSessionHas('error', Verify::CODE_NOT_SENT);

        Notification::assertNothingSent();
    }

    public function test_safe_preview_never_claims_a_code_was_sent(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $user = $this->citizen();

        $this->resend($user)->assertSessionHas('error', Verify::CODE_NOT_SENT);

        Notification::assertNothingSent();
    }

    public function test_an_already_verified_citizen_gets_no_email(): void
    {
        Notification::fake();
        $user = $this->citizen(['email_verified_at' => now(), 'mobile_verified_at' => now()]);

        $this->resend($user)->assertRedirect(route('dashboard'));

        Notification::assertNothingSent();
    }

    #[DataProvider('states')]
    public function test_the_page_says_sent_only_for_an_accepted_message(string $status, ?string $shown, ?string $notShown): void
    {
        $user = $this->citizen();
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'verify_email', 'recipient' => 'ci***@example.test', 'status' => $status, 'user_id' => $user->id]);

        $page = $this->actingAs($user)->get(route('verification.notice'))->assertOk()
            ->assertDontSee('Safe Preview')->assertDontSee('preview only')->assertDontSee('test copy');

        if ($shown) {
            $page->assertSee($shown);
        }

        if ($notShown) {
            $page->assertDontSee($notShown);
        }
    }

    public static function states(): array
    {
        $sent = 'A verification code has been sent to your registered email address.';
        $failed = "We couldn't send your verification code. Please try again later.";

        return [
            'queued'    => ['queued', null, $sent],
            'accepted'  => ['accepted', $sent, $failed],
            'failed'    => ['failed', $failed, $sent],
            'blocked'   => ['blocked', $failed, $sent],
            // Simulated means "not sent" only while Safe Preview is the mode (RealEmailOtpTest covers that case).
            'simulated, outside Safe Preview' => ['simulated', null, $failed],
        ];
    }

    public function test_the_verification_email_carries_the_code_and_no_link(): void
    {
        $user = $this->citizen();

        $mail = (new VerifyEmailNotification('123456', now()->addMinutes(10)))->toMail($user);

        $this->assertNull($mail->viewData['actionUrl']);
        $this->assertStringContainsString('Your verification code is 123456.', implode(' ', $mail->viewData['introLines']));
    }

    public function test_the_reset_page_never_shows_test_copy_or_preview_wording(): void
    {
        foreach ([[], ['services.outbound.mail' => 'approved@example.test'], ['mail.default' => 'log']] as $settings) {
            config($settings);

            $this->get(route('password.request'))->assertOk()
                ->assertDontSee('test copy')->assertDontSee('Safe Preview')->assertDontSee('SMS');
        }
    }
}
