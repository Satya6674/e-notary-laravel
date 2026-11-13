<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\DeliveryLog;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression tests for defects confirmed in docs/COMPLETE_PROJECT_AUDIT.md (2026-11-04). Each was
 * written before its fix and failed on the code as it was. Every account is synthetic.
 */
class AuditFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function person(string $role, array $extra = []): User
    {
        $n = ++$this->n;
        $user = User::create([
            'name' => "Follow-up {$n}", 'email' => "followup{$n}@example.test", 'mobile' => '95600'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null] + $extra)->save();

        return $user->fresh();
    }

    private function application(User $owner, array $extra = []): NotaryRequest
    {
        return NotaryRequest::create($extra + [
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ]);
    }

    /** B-11: a refused login used to be audited as a login followed by a logout. */
    public function test_a_deactivated_or_locked_account_is_refused_without_a_login_being_recorded(): void
    {
        foreach (['deactivated_at' => now(), 'password_reset_required_at' => now()] as $column => $value) {
            $user = $this->person('public', [$column => $value]);

            $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'demo-pass-123'])
                ->assertStatus(401)->assertJson(['message' => 'Invalid login credentials']);

            $this->assertGuest();
            $this->assertSame(0, AuditLog::where('user_id', $user->id)->whereIn('action', ['auth.login', 'auth.logout'])->count(), $column);
        }

        // An active account still signs in and is recorded once.
        $active = $this->person('public');
        $this->postJson('/custom-login', ['mobile' => $active->email, 'password' => 'demo-pass-123'])->assertOk();
        $this->assertSame(1, AuditLog::where('user_id', $active->id)->where('action', 'auth.login')->count());
    }

    /** B-9: the warning after deleting a Notary ignored approved applications. */
    public function test_deleting_a_notary_counts_every_open_application_in_the_warning(): void
    {
        $admin = $this->person('admin');
        $notary = $this->person('notary');
        foreach (['assigned', 'approved', 'correction_requested', 'completed'] as $status) {
            $this->application($this->person('public'), ['status' => $status, 'notary_id' => $notary->id]);
        }

        $this->actingAs($admin)->delete(route('admin.users.delete', $notary), ['reason' => 'Synthetic deletion for testing.', 'confirm' => '1'])
            ->assertRedirect(route('admin.users.deleted'));

        $this->assertStringContainsString('3 open applications are still assigned', (string) session('status'));
    }

    /** B-8: an encrypted job (password reset) that failed was never marked FAILED. */
    public function test_a_failed_encrypted_notification_is_marked_failed_in_the_delivery_history(): void
    {
        $user = $this->person('public');
        $notification = new ResetPasswordNotification('synthetic-token');
        $notification->id = (string) Str::uuid();
        MessageDelivery::create(['channel' => 'mail', 'purpose' => 'password_reset', 'recipient' => 'fo***@example.test', 'status' => 'queued', 'user_id' => $user->id, 'notification_id' => $notification->id]);

        // Exactly how Laravel's queue stores a ShouldBeEncrypted job.
        $command = app(Encrypter::class)->encrypt(serialize(new SendQueuedNotifications(collect([$user]), $notification, ['mail'])));
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => SendQueuedNotifications::class, 'command' => $command]]);

        app(DeliveryLog::class)->failed(new JobFailed('database', $job, new RuntimeException('SMTP refused')));

        $this->assertSame(MessageDelivery::FAILED, MessageDelivery::where('notification_id', $notification->id)->value('status'));
    }

    /** A genuine upload: its type is sniffed from the bytes, as in a real request (a fake guesses from the name). */
    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** M-10: an upload was accepted by content alone, whatever its file name said. */
    public function test_an_upload_must_have_a_matching_file_extension(): void
    {
        Storage::fake('local');
        $citizen = $this->person('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->actingAs($citizen)->post(route('user.upload.store'), [
            'title' => 'Disguised', 'document_type' => 'other', 'description' => 'A PNG named as a web page.',
            'file' => $this->realUpload('payload.html', $png),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, NotaryRequest::count());

        // Control: the same bytes with an honest name are accepted, so the refusal above is the name.
        $this->actingAs($citizen)->post(route('user.upload.store'), [
            'title' => 'Honest', 'document_type' => 'other', 'description' => 'The same PNG, named as one.',
            'file' => $this->realUpload('photo.png', $png),
        ])->assertSessionDoesntHaveErrors('file');
        $this->assertSame(1, NotaryRequest::count());
    }

    /** L-1: /reports filtered on the UTC day while every page shows Indian time. */
    public function test_report_dates_are_indian_days_and_must_be_real_dates(): void
    {
        $admin = $this->person('admin');
        $owner = $this->person('public');
        // 20:00 UTC on 4 November is 01:30 IST on 5 November.
        $late = $this->application($owner);
        $late->forceFill(['created_at' => Carbon::parse('2026-11-04 20:00:00', 'UTC')])->save();

        $csv = $this->actingAs($admin)->get(route('reports.csv', ['from' => '2026-11-05', 'to' => '2026-11-05']))->assertOk()->streamedContent();
        $this->assertStringContainsString($late->request_number, $csv);

        $csv = $this->actingAs($admin)->get(route('reports.csv', ['from' => '2026-11-04', 'to' => '2026-11-04']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($late->request_number, $csv);

        $this->actingAs($admin)->get(route('reports.index', ['from' => 'tomorrow']))->assertSessionHasErrors('from');
        $this->actingAs($admin)->get(route('reports.index', ['from' => '2020-01-01', 'to' => '2026-11-04']))->assertSessionHasErrors('from');
    }

    /** L-4: routes that check a password, and the failed-job retry, had no rate limit. */
    public function test_password_checking_and_retry_routes_are_rate_limited(): void
    {
        foreach (['notary.profile.password', 'user.profile.update', 'admin.delivery.retry'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertTrue(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle:')), $name);
        }
    }
}
