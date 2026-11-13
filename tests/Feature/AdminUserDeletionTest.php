<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use App\Services\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Reads real built messages from the in-memory mailer. No message leaves this process. */
class AdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Requested by the account holder in writing.';

    private const PASSWORD = 'The-test-password1';

    private const RESTORE_REASON = 'Restored at the written request of the holder.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['mail.support_address' => 'help@enotary.example.test']);
    }

    private function person(string $role, array $values = []): User
    {
        return User::factory()->create($values + [
            'role' => $role, 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function admin(): User
    {
        return $this->person('admin');
    }

    private function deleteUser(User $admin, User $target, array $data = [])
    {
        return $this->actingAs($admin)->delete(route('admin.users.delete', $target), $data + ['reason' => self::REASON, 'confirm' => '1']);
    }

    private function restoreUser(User $admin, User $target, array $data = [])
    {
        return $this->actingAs($admin)->post(route('admin.users.restore', $target), $data + ['reason' => self::RESTORE_REASON]);
    }

    private function sent(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => $m->getOriginalMessage()->getSubject(), 'body' => $m->getOriginalMessage()->getTextBody()])
            ->all();
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    private function failingMail(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    }

    private function workingMail(): void
    {
        Mail::purge('smtp');
        config(['mail.default' => 'array']);
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();
    }

    public function test_only_an_admin_sees_the_delete_button(): void
    {
        $admin = $this->admin();
        $citizen = $this->person('public');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertSee('data-delete-user', false)->assertSee(route('admin.users.delete', $citizen), false)
            ->assertSee('Confirm Delete')->assertSee('Deleted Users');

        $this->actingAs($this->person('public'))->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->person('notary'))->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_an_admin_is_not_offered_delete_on_their_own_row(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertDontSee(route('admin.users.delete', $admin), false);
    }

    /** @return array<string, array{string}> */
    public static function nonAdmins(): array
    {
        return ['citizen' => ['public'], 'notary' => ['notary']];
    }

    #[DataProvider('nonAdmins')]
    public function test_non_admins_cannot_reach_any_deletion_route(string $role): void
    {
        $this->admin();
        $actor = $this->person($role);
        $target = $this->person('public');
        $deleted = $this->person('public', ['deleted_at' => now(), 'deactivated_at' => now()]);
        $record = AccountDeletion::create(['reference' => 'DEL-TEST', 'user_id' => $deleted->id, 'deleted_by' => $target->id, 'name' => 'x', 'email' => 'x@example.test', 'role' => 'public', 'reason' => self::REASON, 'deleted_at' => now()]);

        $this->actingAs($actor)->get(route('admin.users.deleted'))->assertForbidden();
        $this->actingAs($actor)->delete(route('admin.users.delete', $target), ['reason' => self::REASON, 'confirm' => '1'])->assertForbidden();
        $this->actingAs($actor)->post(route('admin.users.restore', $deleted))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertForbidden();

        $this->assertNull($target->fresh()->deleted_at);
        $this->assertNotNull($deleted->fresh()->deleted_at);
    }

    public function test_a_guest_cannot_reach_the_deletion_routes(): void
    {
        $target = $this->person('public');

        $this->delete(route('admin.users.delete', $target), ['reason' => self::REASON, 'confirm' => '1'])->assertRedirect(route('login'));

        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_the_request_needs_a_csrf_token(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        $this->app['env'] = 'local';

        $this->deleteUser($admin, $target)->assertStatus(419);

        $this->assertNull($target->fresh()->deleted_at);
    }

    #[DataProvider('roles')]
    public function test_an_admin_can_soft_delete_an_account(string $role): void
    {
        $admin = $this->admin();
        $target = $this->person($role);

        $this->deleteUser($admin, $target)->assertRedirect(route('admin.users.deleted'));

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertTrue($target->fresh()->isDeleted());
        $this->assertTrue($target->fresh()->isDeactivated());

        $record = AccountDeletion::sole();
        $this->assertSame($target->id, $record->user_id);
        $this->assertSame($admin->id, $record->deleted_by);
        $this->assertSame(self::REASON, $record->reason);
        $this->assertSame($target->email, $record->email);
        $this->assertSame($role, $record->role);
        $this->assertNotNull($record->deleted_at);
        $this->assertMatchesRegularExpression('/^DEL-\d{8}-[A-Z0-9]{8}$/', $record->reference);
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['citizen' => ['public'], 'notary' => ['notary']];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRequests(): array
    {
        return [
            'no reason' => [['reason' => '']],
            'short reason' => [['reason' => 'too short']],
            'long reason' => [['reason' => str_repeat('x', 501)]],
            'box not ticked' => [['confirm' => '0']],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_a_valid_reason_and_the_tick_are_required(array $data): void
    {
        $admin = $this->admin();
        $target = $this->person('public');

        $this->deleteUser($admin, $target, $data)->assertSessionHasErrors();

        $this->assertNull($target->fresh()->deleted_at);
        $this->assertSame([], $this->sent());
    }

    public function test_only_the_registered_address_receives_the_email(): void
    {
        $admin = $this->admin();
        $this->person('public', ['email' => 'bystander@example.test']);
        $target = $this->person('public', ['email' => 'exact.person+tag@example.test']);

        $this->deleteUser($admin, $target);

        $this->assertCount(1, $this->sent());
        $this->assertSame('exact.person+tag@example.test', $this->sent()[0]['to']);
    }

    public function test_the_email_holds_the_right_details_and_no_secrets(): void
    {
        $admin = $this->admin();
        $target = $this->person('notary', ['name' => 'Asha Verma', 'email' => 'asha@example.test']);

        $this->deleteUser($admin, $target);

        $record = AccountDeletion::sole();
        ['subject' => $subject, 'body' => $body] = $this->sent()[0];

        $this->assertStringContainsString('deactivated', $subject);

        foreach (['Asha Verma', 'asha@example.test', 'Notary', $record->deleted_at->ist('d M Y, h:i A'), $record->reference, self::REASON, 'help@enotary.example.test', 'deactivated'] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }

        $this->assertStringNotContainsString(self::PASSWORD, $body);
        $this->assertStringNotContainsString((string) $target->password, $body);
    }

    public function test_the_reason_is_escaped_in_the_html_email(): void
    {
        $this->deleteUser($this->admin(), $this->person('public'), ['reason' => 'Reason with <script>alert(1)</script> inside']);

        $html = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_the_email_waits_for_the_commit(): void
    {
        $this->useDatabaseQueue();
        $this->deleteUser($this->admin(), $this->person('public'));

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame([], $this->sent());
        $this->assertSame('queued', AccountDeletion::sole()->noticeStatus('deletion'));

        $payload = DB::table('jobs')->value('payload');
        $this->assertStringContainsString('AccountDeletedNotification', $payload);
        $this->assertStringNotContainsString(self::REASON, $payload);
    }

    public function test_a_refused_deletion_rolls_back_and_sends_nothing(): void
    {
        $admin = $this->admin();

        $this->deleteUser($admin, $admin);

        $this->assertSame(0, AccountDeletion::count());
        $this->assertSame([], $this->sent());
    }

    public function test_an_smtp_failure_does_not_undo_the_deletion(): void
    {
        $this->useDatabaseQueue();
        $this->failingMail();
        $target = $this->person('public');

        $this->deleteUser($this->admin(), $target)->assertRedirect(route('admin.users.deleted'));
        Artisan::call('queue:work', ['--once' => true]);

        $this->assertTrue($target->fresh()->isDeleted());
        $this->assertSame('queued', AccountDeletion::sole()->noticeStatus('deletion'), 'Still has retries left.');
        $this->assertStringContainsString('TransportException', (string) MessageDelivery::where('channel', 'mail')->value('error'));
        $this->assertStringNotContainsString($target->email, json_encode(MessageDelivery::first()->toArray()));
    }

    private function exhaustRetries(): void
    {
        foreach (range(1, 3) as $attempt) {
            Artisan::call('queue:work', ['--once' => true]);
            $this->travel(11)->minutes();
        }
    }

    public function test_a_deleted_admin_no_longer_counts_as_an_active_admin(): void
    {
        $actor = $this->admin();
        $deleted = $this->admin();

        $this->deleteUser($actor, $deleted);

        $this->assertSame([$actor->id], User::where('role', 'admin')->whereNull('deactivated_at')->pluck('id')->all());
        $this->assertFalse($deleted->fresh()->can('delete', $this->person('public')));
    }

    public function test_a_queue_outage_keeps_the_deletion_and_offers_a_retry(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        config(['queue.default' => 'not-a-connection']);

        $this->deleteUser($admin, $target)->assertRedirect(route('admin.users.deleted'));

        $record = AccountDeletion::sole();
        $this->assertTrue($target->fresh()->isDeleted());
        $this->assertSame('not_queued', $record->noticeStatus('deletion'));
        $this->assertTrue($record->canRetry('deletion'));

        config(['queue.default' => 'sync']);
        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertSessionHas('status');

        $this->assertSame([$target->email], array_column($this->sent(), 'to'));
    }

    public function test_a_failed_email_can_be_retried_once_and_safely(): void
    {
        $this->useDatabaseQueue();
        $this->failingMail();
        $admin = $this->admin();
        $target = $this->person('public');
        $this->deleteUser($admin, $target);
        $this->exhaustRetries();

        $record = AccountDeletion::sole();
        $this->assertSame('failed', $record->noticeStatus('deletion'));
        $this->assertTrue($record->canRetry('deletion'));
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertOk()->assertSee('Failed')->assertSee('Retry');

        $this->workingMail();
        $first = $record->deletion_notification_id;

        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertSessionHas('error');
        Artisan::call('queue:work', ['--stop-when-empty' => true]);

        $record->refresh();
        $this->assertNotSame($first, $record->deletion_notification_id);
        $this->assertSame('sent', $record->noticeStatus('deletion'));
        $this->assertCount(1, $this->sent());
        $this->assertSame($target->email, $this->sent()[0]['to']);
        $this->assertFalse($record->canRetry('deletion'));
        $this->assertSame(1, AuditLog::where('action', 'admin.user_notice_retried')->count());
    }

    public function test_a_redelivered_job_does_not_send_twice(): void
    {
        $target = $this->person('public');
        $this->deleteUser($this->admin(), $target);
        $record = AccountDeletion::sole();

        $target->fresh()->notifyNow(new AccountDeletedNotification($record->id, $record->deletion_notification_id));

        $this->assertCount(1, $this->sent());
    }

    public function test_a_notice_replaced_by_a_retry_stays_silent(): void
    {
        $target = $this->person('public');
        $this->deleteUser($this->admin(), $target);
        $record = AccountDeletion::sole();

        $target->fresh()->notifyNow(new AccountDeletedNotification($record->id, (string) Str::uuid()));

        $this->assertCount(1, $this->sent());
    }

    public function test_a_second_delete_is_refused(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');

        $this->deleteUser($admin, $target);
        $this->deleteUser($admin, $target)->assertSessionHas('error');

        $this->assertSame(1, AccountDeletion::count());
        $this->assertCount(1, $this->sent());
    }

    public function test_a_queued_or_sent_notice_cannot_be_retried(): void
    {
        $this->useDatabaseQueue();
        $admin = $this->admin();
        $this->deleteUser($admin, $this->person('public'));
        $record = AccountDeletion::sole();

        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertSessionHas('error');
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['--stop-when-empty' => true]);
        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [$record, 'deletion']))->assertSessionHas('error');
        $this->assertCount(1, $this->sent());
    }

    public function test_an_unknown_notice_type_is_rejected(): void
    {
        $admin = $this->admin();
        $this->deleteUser($admin, $this->person('public'));

        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [AccountDeletion::sole(), 'other']))->assertSessionHasErrors('notice');
    }

    public function test_the_allowlist_blocks_the_email_without_redirecting_it(): void
    {
        config(['services.outbound.mail' => 'someone.else@example.test']);
        $admin = $this->admin();
        $target = $this->person('public');

        $this->deleteUser($admin, $target);

        $this->assertTrue($target->fresh()->isDeleted());
        $this->assertSame([], $this->sent());
        $this->assertSame('blocked', AccountDeletion::sole()->noticeStatus('deletion'));
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertSee('Blocked');
    }

    public function test_a_deleted_account_cannot_log_in(): void
    {
        $target = $this->person('public');
        $this->deleteUser($this->admin(), $target);
        Auth::logout();

        $this->postJson('/custom-login', ['mobile' => $target->email, 'password' => self::PASSWORD])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_a_live_session_ends_at_its_next_request(): void
    {
        $target = $this->person('public');
        app(AccountDeletionService::class)->delete($this->admin(), $target, self::REASON);

        $this->actingAs($target->fresh())->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_remember_login_is_revoked(): void
    {
        $target = $this->person('public', ['remember_token' => 'old-token']);

        $this->deleteUser($this->admin(), $target);

        $this->assertNotSame('old-token', $target->fresh()->remember_token);
    }

    public function test_a_pending_reset_and_code_are_revoked_and_no_new_reset_is_sent(): void
    {
        $target = $this->person('public', ['mobile' => '9876543210']);
        DB::table('password_resets')->insert(['email' => $target->email, 'token' => 'x', 'created_at' => now()]);
        DB::table('otp_verifications')->insert(['mobile' => '9876543210', 'otp' => 'x', 'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'created_at' => now()]);

        $this->deleteUser($this->admin(), $target);
        Auth::logout();
        $this->post(route('password.email'), ['identifier' => $target->email]);

        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertCount(1, $this->sent(), 'Only the deletion notice.');
    }

    public function test_a_deleted_notary_cannot_be_assigned_and_is_not_approved(): void
    {
        $admin = $this->admin();
        $notary = $this->person('notary', ['name' => 'Deleted Notary Person']);
        $application = NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->person('public')->id, 'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending']);

        $this->deleteUser($admin, $notary);

        $this->assertFalse($notary->fresh()->isApprovedNotary());
        $this->actingAs($admin)->get(route('admin.requests.show', $application))->assertOk()->assertDontSee('Deleted Notary Person');
    }

    public function test_the_admin_is_told_about_open_work_on_a_deleted_notary(): void
    {
        $admin = $this->admin();
        $notary = $this->person('notary');
        NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->person('public')->id, 'notary_id' => $notary->id, 'document_type' => 'will', 'purpose' => 'x', 'status' => 'assigned']);

        $this->deleteUser($admin, $notary)->assertSessionHas('status', fn ($s) => str_contains($s, '1 open applications'));
    }

    public function test_deleted_accounts_leave_the_main_list_and_join_the_deleted_list(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['name' => 'Moved Person']);

        $this->deleteUser($admin, $target);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertDontSee('Moved Person');
        $this->actingAs($admin)->get(route('admin.users.deleted'))->assertOk()
            ->assertSee('Moved Person')->assertSee(self::REASON)->assertSee(AccountDeletion::sole()->reference)->assertSee($admin->name)->assertSee('Sent');
    }

    /** Module 3 (Decision A): a restored account cannot use its old password; it sets a new one by email. */
    public function test_a_restored_account_must_set_a_new_password_before_it_can_log_in(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        $this->deleteUser($admin, $target);

        $this->actingAs($admin)->post(route('admin.users.restore', $target), ['reason' => self::RESTORE_REASON])->assertRedirect(route('admin.users.deleted'));

        $target = $target->fresh();
        $record = AccountDeletion::sole();
        $this->assertFalse($target->isDeleted());
        $this->assertFalse($target->isDeactivated());
        $this->assertTrue($target->mustResetPassword());
        $this->assertTrue($target->isRetiredPassword(self::PASSWORD));
        $this->assertSame($admin->id, $record->restored_by);
        $this->assertNotNull($record->restored_at);

        Auth::logout();
        Auth::forgetGuards();
        $this->postJson('/custom-login', ['mobile' => $target->email, 'password' => self::PASSWORD])->assertStatus(401);

        // The way back in: the emailed link, after which the old password is still refused.
        $reset = collect($this->sent())->last(fn ($m) => str_contains($m['subject'], 'Reset your'));
        $this->assertNotNull($reset);
        preg_match('#/reset-password/([^?"\s]+)#', $reset['body'], $token);

        $this->post(route('password.update'), ['token' => $token[1], 'email' => $target->email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['token' => $token[1], 'email' => $target->email, 'password' => 'A-brand-new-pass-1', 'password_confirmation' => 'A-brand-new-pass-1'])
            ->assertRedirect(route('home'));

        $this->assertFalse($target->fresh()->mustResetPassword());
        $this->postJson('/custom-login', ['mobile' => $target->email, 'password' => 'A-brand-new-pass-1'])->assertOk()->assertJson(['success' => true]);
    }

    public function test_the_deletion_time_never_changes_after_a_restore_or_retry(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        config(['queue.default' => 'not-a-connection']);
        $this->deleteUser($admin, $target);
        $recorded = DB::table('account_deletions')->value('deleted_at');
        config(['queue.default' => 'sync']);

        $this->travel(3)->hours();
        $this->actingAs($admin)->post(route('admin.users.deletion.retry', [AccountDeletion::sole(), 'deletion']))->assertSessionHas('status');
        $this->assertSame($recorded, DB::table('account_deletions')->value('deleted_at'));

        $this->travel(3)->hours();
        $this->restoreUser($admin, $target);
        $this->assertSame($recorded, DB::table('account_deletions')->value('deleted_at'));
    }

    public function test_the_deletion_time_column_does_not_update_itself(): void
    {
        $column = collect(\Illuminate\Support\Facades\Schema::getColumns('account_deletions'))->firstWhere('name', 'deleted_at');

        $this->assertTrue($column['nullable']);
        $this->assertStringNotContainsStringIgnoringCase('on update', (string) ($column['extra'] ?? ''));
    }

    public function test_the_restore_email_goes_to_the_same_address(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['name' => 'Ravi Kumar', 'email' => 'ravi@example.test']);
        $this->deleteUser($admin, $target);

        $this->restoreUser($admin, $target);

        // Module 3: deletion notice, restore notice, then the separate reset link, all to the one address.
        $this->assertSame(['ravi@example.test', 'ravi@example.test', 'ravi@example.test'], array_column($this->sent(), 'to'));
        ['subject' => $subject, 'body' => $body] = $this->sent()[1];
        $this->assertStringContainsString('restored', $subject);
        $this->assertStringContainsString('Ravi Kumar', $body);
        $this->assertStringContainsString(AccountDeletion::sole()->reference, $body);
        $this->assertStringContainsString('Your previous password no longer works', $body);
        $this->assertStringContainsString('Reset your', $this->sent()[2]['subject']);
        $this->assertSame('sent', AccountDeletion::sole()->noticeStatus('restore'));
    }

    /** Module 3 (Decision A): the phone proof ends at restore, for a number that was proven before. */
    public function test_a_restore_requires_fresh_phone_proof(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['mobile_verified_at' => now()->subMonth()]);
        $this->assertTrue($target->fresh()->hasVerifiedMobile());
        $this->deleteUser($admin, $target);

        $this->restoreUser($admin, $target);

        $restored = $target->fresh();
        $this->assertNull($restored->mobile_verified_at);
        $this->assertFalse($restored->canRecoverByMobile(), 'no SMS reset on an unproven number');
        $this->assertNotNull($restored->email_verified_at, 'the mailbox is proven again by the reset link itself');

        // After the new password, the Citizen area waits for a fresh SMS proof.
        $restored->forceFill(['password_reset_required_at' => null])->save();
        Auth::forgetUser();
        $this->actingAs($restored->fresh())->get(route('dashboard'))->assertRedirect(route('verification.mobile.notice'));
    }

    public function test_an_account_deactivated_before_deletion_stays_deactivated(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['deactivated_at' => now()->subDay()]);
        $this->deleteUser($admin, $target);

        $this->restoreUser($admin, $target);

        $this->assertFalse($target->fresh()->isDeleted());
        $this->assertTrue($target->fresh()->isDeactivated());
    }

    public function test_restoring_twice_is_refused(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        $this->deleteUser($admin, $target);
        $this->restoreUser($admin, $target);

        $this->restoreUser($admin, $target)->assertSessionHas('error');

        // Module 3: deletion notice, restore notice and one reset link; the refused second try sends nothing.
        $this->assertCount(3, $this->sent());
        $this->assertSame(1, DB::table('password_resets')->count());
    }

    public function test_a_second_deletion_gets_its_own_reference_and_history(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');
        $this->deleteUser($admin, $target);
        $this->restoreUser($admin, $target);
        $this->deleteUser($admin, $target);

        $this->assertSame(2, AccountDeletion::distinct('reference')->count('reference'));
        // Module 3: a restore also sends the reset link, so 1 + 2 + 1.
        $this->assertCount(4, $this->sent());
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();
        $this->admin();

        $this->deleteUser($admin, $admin)->assertSessionHas('error', 'You cannot delete your own account.');

        $this->assertNull($admin->fresh()->deleted_at);
    }

    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $inactive = $this->person('admin', ['deactivated_at' => now()]);
        $last = $this->admin();

        $this->expectException(AuthorizationException::class);

        try {
            app(AccountDeletionService::class)->delete($inactive, $last, self::REASON);
        } finally {
            $this->assertNull($last->fresh()->deleted_at);
        }
    }

    public function test_the_policy_refuses_the_last_active_admin_for_any_actor(): void
    {
        $last = $this->admin();
        $stranger = new User(['role' => 'admin']);
        $stranger->id = $last->id + 1000;

        $this->assertSame('The last active administrator cannot be deleted.', $stranger->can('delete', $last) ? 'allowed' : \Illuminate\Support\Facades\Gate::forUser($stranger)->inspect('delete', $last)->message());
    }

    public function test_one_admin_may_delete_another_when_one_remains(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->deleteUser($admin, $other);

        $this->assertTrue($other->fresh()->isDeleted());
    }

    public function test_applications_documents_and_audit_history_are_preserved(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['name' => 'Kept Person']);
        $application = NotaryRequest::create(['request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $target->id, 'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending']);
        $document = Document::create(['request_id' => $application->id, 'title' => 'A', 'original_file' => 'documents/a.pdf']);
        AuditLog::record('test.before', $target, [], $target->id);

        $this->deleteUser($admin, $target);

        $this->assertSame('Kept Person', $application->fresh()->user->name);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->actingAs($admin)->get(route('admin.requests.show', $application))->assertOk()->assertSee('Kept Person');
        $this->assertSame(1, AuditLog::where('action', 'test.before')->count());

        $entry = AuditLog::where('action', 'admin.user_deleted')->sole();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame($target->id, $entry->model_id);
        $this->assertSame(AccountDeletion::sole()->reference, $entry->meta['reference']);

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_a_deleted_account_cannot_be_edited_or_reactivated(): void
    {
        $admin = $this->admin();
        $target = $this->person('public', ['email' => 'frozen@example.test']);
        $this->deleteUser($admin, $target);

        $this->actingAs($admin)->get(route('admin.users.edit', $target))->assertRedirect(route('admin.users.deleted'));
        $this->actingAs($admin)->put(route('admin.users.update', $target), ['name' => 'x', 'email' => 'other@example.test', 'role' => 'public'])->assertRedirect(route('admin.users.deleted'));
        $this->actingAs($admin)->post(route('admin.users.reactivate', $target))->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('admin.users.destroy', $target), ['reason' => 'because'])->assertSessionHas('error');

        // The real address is held on the deletion record, and the edit did not replace it.
        $this->assertSame('frozen@example.test', $target->fresh()->contactEmail());
        $this->assertSame('frozen@example.test', AccountDeletion::sole()->email);
        $this->assertStringEndsWith('@deleted.invalid', $target->fresh()->email);
        $this->assertTrue($target->fresh()->isDeleted());
    }

    public function test_the_existing_deactivate_action_still_works(): void
    {
        $admin = $this->admin();
        $target = $this->person('public');

        $this->actingAs($admin)->delete(route('admin.users.destroy', $target), ['reason' => 'Temporary hold'])->assertRedirect();

        $this->assertTrue($target->fresh()->isDeactivated());
        $this->assertFalse($target->fresh()->isDeleted());
        $this->assertSame(0, AccountDeletion::count());
    }
}
