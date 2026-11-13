<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * An administrator can send a new password link for a plain restore whose link failed, never
 * went, or expired. Only one link ever works, the account stays locked until it is used, every
 * attempt is audited, and the token appears nowhere but inside the email. Mail is the array
 * mailer; every account is synthetic.
 */
class RestoreResetLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Old-demo-pass-1';

    private User $admin;

    private User $citizen;

    private AccountDeletion $deletion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin.one@example.test', '9422200001');
        $this->user('admin', 'admin.two@example.test', '9422200002');
        $this->citizen = $this->user('public', 'restored.person@example.test', '9422200010');

        $service = app(AccountDeletionService::class);
        $service->delete($this->admin, $this->citizen, 'Synthetic deletion for testing.');
        $this->deletion = $service->restore($this->admin, $this->citizen->fresh(), 'Synthetic restore for testing.');
    }

    private function user(string $role, string $email, string $mobile): User
    {
        $user = User::create([
            'name' => 'Demo '.$mobile, 'email' => $email, 'mobile' => $mobile, 'password' => Hash::make(self::PASSWORD),
            'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** Every reset token emailed to the restored person, oldest first. */
    private function tokens(): array
    {
        $found = [];

        foreach (app('mailer')->getSymfonyTransport()->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            if ($message->getTo()[0]->getAddress() === 'restored.person@example.test'
                && preg_match('#/reset-password/([^?"\s]+)#', (string) $message->getHtmlBody(), $m)) {
                $found[] = $m[1];
            }
        }

        return array_values(array_unique($found));
    }

    private function resend()
    {
        return $this->actingAs($this->admin)->post(route('admin.users.deletion.reset-link', $this->deletion));
    }

    /** As if the worker had failed to deliver the current link. */
    private function failCurrentLink(): void
    {
        $id = AuditLog::where('model_id', $this->citizen->id)->whereIn('action', ['admin.user_restored', AccountDeletionService::RESET_LINK_RESENT])
            ->latest('id')->first()->meta['reset_notification_id'];
        MessageDelivery::where('notification_id', $id)->update(['status' => MessageDelivery::FAILED]);
    }

    private function state(): array
    {
        return app(AccountDeletionService::class)->resetLinkState($this->deletion->fresh());
    }

    private function assertLocked(): void
    {
        $this->assertTrue($this->citizen->fresh()->mustResetPassword(), 'The account stays locked.');
        $this->postJson(route('login.submit'), ['mobile' => 'restored.person@example.test', 'password' => self::PASSWORD])->assertStatus(401);
    }

    public function test_the_restore_link_is_tracked_and_a_working_delivered_link_cannot_be_replaced(): void
    {
        $this->assertCount(1, $this->tokens());
        $this->assertSame(['status' => 'sent', 'resendable' => false], array_intersect_key($this->state(), ['status' => 1, 'resendable' => 1]));

        $this->resend()->assertSessionHas('error', 'The current link was accepted by the mail service and still works. It can be replaced once it expires.');

        $this->assertCount(1, $this->tokens(), 'No second email.');
        $this->assertSame(1, AuditLog::where('action', AccountDeletionService::RESET_LINK_REFUSED)->count(), 'The refused attempt is audited.');
        $this->assertSame(0, AuditLog::where('action', AccountDeletionService::RESET_LINK_RESENT)->count());
        $this->assertLocked();
    }

    public function test_a_failed_link_is_replaced_and_only_the_new_one_works(): void
    {
        [$old] = $this->tokens();
        $this->failCurrentLink();
        $this->travel(2)->minutes();

        $this->assertSame(['status' => 'failed', 'resendable' => true], array_intersect_key($this->state(), ['status' => 1, 'resendable' => 1]));
        $this->resend()->assertSessionHas('status');

        $tokens = $this->tokens();
        $this->assertCount(2, $tokens);
        $new = $tokens[1];
        $this->assertNotSame($old, $new);

        // One token per account: the old link is dead, the new one works.
        $broker = Password::broker();
        $this->assertFalse($broker->tokenExists($this->citizen->fresh(), $old));
        $this->assertTrue($broker->tokenExists($this->citizen->fresh(), $new));
        $this->assertSame(1, DB::table(config('auth.passwords.users.table'))->where('email', 'restored.person@example.test')->count());
        $this->assertLocked();

        $this->post(route('password.update'), ['token' => $old, 'email' => 'restored.person@example.test', 'password' => 'New-demo-pass-2', 'password_confirmation' => 'New-demo-pass-2'])
            ->assertSessionHasErrors();
        $this->assertLocked();

        $this->post(route('password.update'), ['token' => $new, 'email' => 'restored.person@example.test', 'password' => 'New-demo-pass-2', 'password_confirmation' => 'New-demo-pass-2'])
            ->assertRedirect(route('home'));
        $this->assertFalse($this->citizen->fresh()->mustResetPassword());

        // Used: nothing more to send.
        $this->assertSame('used', $this->state()['status']);
        $this->resend()->assertSessionHas('error', 'The person has already set a new password, so no link is needed.');
    }

    public function test_the_resend_is_audited_and_the_token_appears_in_no_record_message_or_url(): void
    {
        $this->failCurrentLink();
        $this->travel(2)->minutes();

        $url = route('admin.users.deletion.reset-link', $this->deletion);
        $response = $this->resend();
        $new = $this->tokens()[1];

        $entry = AuditLog::where('action', AccountDeletionService::RESET_LINK_RESENT)->sole();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($this->deletion->reference, $entry->meta['reference']);
        $this->assertSame('failed', $entry->meta['previous_status']);
        $this->assertNotEmpty($entry->meta['reset_notification_id']);
        $this->assertSame(MessageDelivery::ACCEPTED, MessageDelivery::where('notification_id', $entry->meta['reset_notification_id'])->value('status'));

        $this->assertStringNotContainsString($new, json_encode(AuditLog::pluck('meta')));
        $this->assertStringNotContainsString($new, $url);
        $this->assertStringNotContainsString($new, (string) session('status'));
        $this->assertStringNotContainsString($new, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($new, json_encode(MessageDelivery::all()->toArray()));
    }

    public function test_an_expired_link_can_be_replaced(): void
    {
        $this->travel(61)->minutes();

        $this->assertSame(['status' => 'expired', 'resendable' => true], array_intersect_key($this->state(), ['status' => 1, 'resendable' => 1]));
        $this->resend()->assertSessionHas('status');
        $this->assertCount(2, $this->tokens());
    }

    public function test_two_resends_in_a_row_make_only_one_new_link(): void
    {
        $this->failCurrentLink();
        $this->travel(2)->minutes();

        $this->resend()->assertSessionHas('status');
        // Immediately again: the new link was delivered and works, and the broker pause applies too.
        $this->resend()->assertSessionHas('error');

        $this->assertCount(2, $this->tokens());
        $this->assertSame(1, AuditLog::where('action', AccountDeletionService::RESET_LINK_RESENT)->count());
        $this->assertSame(1, AuditLog::where('action', AccountDeletionService::RESET_LINK_REFUSED)->count());
    }

    public function test_a_resend_within_a_minute_of_the_last_link_waits(): void
    {
        $this->failCurrentLink();

        $this->resend()->assertSessionHas('error', 'A link was created less than a minute ago. Please wait a minute and try again.');
        $this->assertCount(1, $this->tokens());
    }

    public function test_a_link_that_could_not_be_queued_is_recorded_and_can_be_tried_again(): void
    {
        $this->failCurrentLink();
        $this->travel(2)->minutes();

        // The mail cannot even be handed over: as if the queue were down.
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class implements \Illuminate\Contracts\Notifications\Dispatcher {
            public function send($notifiables, $notification)
            {
                throw new \RuntimeException('Simulated queue failure.');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new \RuntimeException('Simulated queue failure.');
            }
        });

        $this->resend()->assertSessionHas('error', 'A new link was made but could not be queued. This was recorded. Please try again in a minute.');

        $this->assertSame(1, AuditLog::where('action', AccountDeletionService::RESET_LINK_FAILED)->count());
        $this->assertLocked();
    }

    public function test_a_recovery_restore_or_a_later_deletion_is_not_handled_here(): void
    {
        AccountRecoveryRequest::create(['reference' => 'REC-SYNTH', 'account_deletion_id' => $this->deletion->id, 'old_user_id' => $this->citizen->id, 'requester_id' => $this->citizen->id, 'applicant_name' => 'Synthetic', 'verified_email' => 'restored.person@example.test', 'verified_mobile' => '9422200010', 'match_type' => 'email', 'email_proof' => 'emailed_code', 'mobile_proof' => 'none', 'environment' => 'production', 'status' => AccountRecoveryRequest::APPROVED]);
        $this->assertSame('recovery', $this->state()['status']);
        AccountRecoveryRequest::query()->delete();

        app(AccountDeletionService::class)->delete($this->admin, $this->citizen->fresh(), 'Synthetic second deletion.');
        $this->assertSame('superseded', $this->state()['status']);
        $this->resend()->assertSessionHas('error', 'This account was deleted again after this restore.');
    }

    public function test_a_resend_through_the_log_mailer_writes_no_usable_token(): void
    {
        $log = storage_path('framework/testing/resend-log-'.getmypid().'-'.bin2hex(random_bytes(4)).'.log');
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'resend-test', 'logging.channels.resend-test' => ['driver' => 'single', 'path' => $log]]);
        app('mail.manager')->forgetMailers();

        // The token only exists inside the notification; take it from there to look for it.
        $sent = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($event) use (&$sent) {
            if ($event->notification instanceof \App\Notifications\ResetPasswordNotification) {
                $sent[] = $event->notification->token;
            }
        });

        $this->failCurrentLink();
        $this->travel(2)->minutes();
        $this->resend()->assertSessionHas('status');

        foreach (app('log')->channel('resend-test')->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }
        $written = quoted_printable_decode((string) @file_get_contents($log));
        @unlink($log);

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Reset your', $written, 'The email was written to the log.');
        $this->assertStringContainsString('/reset-password/'.\App\Support\LogMailRedactor::MARK, $written);
        $this->assertStringNotContainsString($sent[0], $written);
        $this->assertStringNotContainsString(substr($sent[0], 0, 16), $written);
    }

    public function test_a_queued_link_says_it_waits_for_a_worker(): void
    {
        $id = AuditLog::where('model_id', $this->citizen->id)->where('action', 'admin.user_restored')->sole()->meta['reset_notification_id'];
        MessageDelivery::where('notification_id', $id)->update(['status' => MessageDelivery::QUEUED]);

        $this->assertSame(['status' => 'queued', 'resendable' => false], array_intersect_key($this->state(), ['status' => 1, 'resendable' => 1]));
        $this->actingAs($this->admin)->get(route('admin.users.deleted'))->assertOk()
            ->assertSee('Waits for the queue worker, which is not running.')->assertDontSee('Send a new link');

        $this->resend()->assertSessionHas('error', 'The current link is waiting in the queue and still works. It can be replaced once it fails or expires.');
    }

    public function test_only_an_administrator_can_ask_and_the_page_shows_the_state(): void
    {
        $other = $this->user('public', 'other.citizen@example.test', '9422200011');

        $this->actingAs($other)->post(route('admin.users.deletion.reset-link', $this->deletion))->assertStatus(403);

        $this->failCurrentLink();
        $this->travel(2)->minutes();
        $this->actingAs($this->admin)->get(route('admin.users.deleted'))->assertOk()
            ->assertSee('Password link')->assertSee('Send a new link');
    }
}
