<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-28 hardening pass: per-number OTP limits,
 * safe provider failure handling, no OTP leakage, private document storage
 * and download authorisation.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    private function user(array $overrides = []): User
    {
        static $i = 100;
        $i++;

        return User::create(array_merge([
            'name' => "User {$i}", 'email' => "u{$i}@example.com", 'mobile' => '9000000'.$i,
            'password' => Hash::make('secret123'), 'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ], $overrides));
    }

    private function upload(User $user): Document
    {
        $this->actingAs($user)->post('/user/upload', [
            'title' => 'My Affidavit', 'document_type' => 'affidavit', 'description' => 'test',
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('user.documents'));

        return Document::latest('id')->firstOrFail();
    }

    // ------------------------------------- role separation, headers, limiters

    public function test_admin_and_notary_cannot_use_the_citizen_area(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);
        $citizen = $this->user();

        foreach (['/dashboard', '/user/upload', '/user/documents', '/user/profile'] as $path) {
            $this->actingAs($admin)->get($path)->assertForbidden();
            $this->actingAs($notary)->get($path)->assertForbidden();
            $this->actingAs($citizen)->get($path)->assertOk();
        }

        // Their own areas still work.
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($notary)->get('/notary/dashboard')->assertOk();
    }

    public function test_security_headers_are_present_on_web_responses(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_rate_limiters_are_independent_per_feature(): void
    {
        // Exhaust the password-request limiter (5/min)...
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['identifier' => "x{$i}@example.com"])->assertRedirect();
        }
        $this->post('/forgot-password', ['identifier' => 'x6@example.com'])->assertStatus(429);

        // ...login and registration from the same IP are NOT affected.
        $this->postJson('/custom-login', ['mobile' => 'nobody@example.com', 'password' => 'x'])->assertStatus(401);
        $this->postJson('/custom-register', ['name' => '', 'email' => 'bad', 'mobile' => '1', 'password' => 'x', 'password_confirmation' => 'x', 'role' => 'public', 'accept_terms' => '1'])->assertStatus(422);
        $this->post('/contact', ['name' => 'A', 'email' => 'a@example.com', 'subject' => 'General Inquiry', 'message' => 'Hello, just testing the form.'])->assertRedirect();
    }

    // -------------------------------------------------- SMS OTP limits

    public function test_second_reset_request_within_cooldown_sends_no_second_code(): void
    {
        $user = $this->user();
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertSessionHas('status');
        $this->post('/forgot-password', ['identifier' => $user->mobile])->assertSessionHas('error', \App\Http\Controllers\Auth\ForgotPasswordController::RESEND_WAIT);

        $this->assertDatabaseCount('otp_verifications', 1);
        Notification::assertSentToTimes($user, \App\Notifications\PasswordResetCodeNotification::class, 1);
    }

    public function test_hourly_cap_blocks_the_sixth_reset_code(): void
    {
        $user = $this->user();
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        for ($i = 0; $i < 6; $i++) {
            $this->travel(61 * $i)->seconds();
            // Fresh session each time so the IP throttle (5/min) is not what stops us.
            $this->flushSession();
            $this->post('/forgot-password', ['identifier' => $user->mobile]);
            $this->travelBack();
        }

        $this->assertDatabaseCount('otp_verifications', 5);
        Notification::assertSentToTimes($user, \App\Notifications\PasswordResetCodeNotification::class, 5);
    }

    public function test_a_failed_reset_email_keeps_the_generic_response_and_logs_safely(): void
    {
        $user = $this->user();
        $this->mock(SmsService::class, fn ($m) => $m->shouldNotReceive('send'));

        // A real send that fails: the real notification channel, SMTP to a closed local port.
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'username' => null, 'password' => null, 'timeout' => 1]]);
        app('mail.manager')->forgetMailers();
        Log::spy();

        $response = $this->post('/forgot-password', ['identifier' => $user->mobile]);
        $response->assertRedirect()->assertSessionHas('status', \App\Http\Controllers\Auth\ForgotPasswordController::GENERIC_STATUS)->assertSessionHasNoErrors();

        $this->assertSame(\App\Models\MessageDelivery::FAILED, \App\Models\MessageDelivery::where(['user_id' => $user->id, 'purpose' => 'password_reset_code'])->value('status'));
        $row = DB::table('otp_verifications')->where('mobile', \App\Services\OtpService::emailKey($user))->sole();
        $this->assertTrue(now()->greaterThanOrEqualTo($row->expires_at), 'a code nobody received is cancelled');

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($user) {
            $blob = $message.' '.json_encode($context);

            return $message === 'Email could not be sent' && ! str_contains($blob, $user->email) && ! str_contains($blob, $user->mobile);
        })->atLeast()->once();
    }

    public function test_sms_service_handles_missing_credentials_without_network_call(): void
    {
        config(['services.msg91.auth_key' => null, 'services.msg91.template_id' => 'x']);
        Http::fake();

        $svc = new SmsService();
        $this->assertFalse($svc->send('9123456789', '123456'));
        $this->assertSame('not_configured', $svc->lastError);
        Http::assertNothingSent();
    }

    public function test_sms_service_treats_http200_with_error_type_as_failure_and_never_logs_otp(): void
    {
        config(['services.msg91.auth_key' => 'k', 'services.msg91.template_id' => 't']);
        Log::spy();

        Http::fake([
            SmsService::ENDPOINT => Http::sequence()
                ->push(['type' => 'error', 'message' => 'invalid template id'], 200)
                ->push(['type' => 'success', 'request_id' => 'abc'], 200),
        ]);

        $svc = new SmsService();
        $this->assertFalse($svc->send('+91 91234 56789', '654321'));
        $this->assertStringStartsWith('provider:', $svc->lastError);
        $this->assertTrue($svc->send('9123456789', '654321'));

        Http::assertSent(fn ($req) => $req->url() === SmsService::ENDPOINT
            && $req['mobile'] === '919123456789'
            && $req->hasHeader('authkey'));

        foreach (['info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, function ($message, $context = []) {
                $blob = $message.' '.json_encode($context);

                return str_contains($blob, '654321') || str_contains($blob, '9123456789') || str_contains($blob, '"k"');
            });
        }
    }

    public function test_sms_service_forces_ipv4_for_msg91_requests(): void
    {
        config(['services.msg91.auth_key' => 'k', 'services.msg91.template_id' => 't']);
        $captured = null;

        // The fake callback receives the Guzzle options array for the request.
        Http::fake(function ($request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response(['type' => 'success'], 200);
        });

        $this->assertTrue((new SmsService())->send('9123456789', '222222'));
        $this->assertIsArray($captured);
        $this->assertSame('v4', $captured['force_ip_resolve'] ?? null, 'MSG91 requests must be forced to IPv4');
        $this->assertSame(10, $captured['timeout'] ?? null, 'existing timeout preserved');
        Http::assertSentCount(1);
    }

    // ------------------------------------ MSG91 response parsing / request id

    private function msg91Config(): void
    {
        config(['services.msg91.auth_key' => 'k', 'services.msg91.template_id' => 't']);
    }

    public function test_success_with_request_id_is_captured_and_logged_masked(): void
    {
        $this->msg91Config();
        Log::spy();
        Http::fake([SmsService::ENDPOINT => Http::response(['request_id' => '366a4277754a5747794e7434', 'type' => 'success'], 200)]);

        $svc = new SmsService();
        $this->assertTrue($svc->send('9123456789', '135790'));
        $this->assertSame('366a4277754a5747794e7434', $svc->lastRequestId);
        $this->assertNull($svc->lastError);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'MSG91 OTP accepted'
                && ($context['request_id'] ?? null) === '366a4277754a5747794e7434'
                && ($context['mobile'] ?? '') === '******6789'
                && ! str_contains(json_encode($context), '135790');
        })->once();
    }

    public function test_success_without_or_with_malformed_request_id_is_accepted_but_id_is_null(): void
    {
        $this->msg91Config();
        Http::fake([SmsService::ENDPOINT => Http::sequence()
            ->push(['type' => 'success'], 200)                                   // missing
            ->push(['type' => 'success', 'request_id' => ['nested' => 'x']], 200) // wrong type
            ->push(['type' => 'success', 'request_id' => 'bad id with spaces'], 200)
            ->push(['type' => 'success', 'request_id' => str_repeat('a', 65)], 200),
        ]);

        $svc = new SmsService();
        foreach (range(1, 4) as $i) {
            $this->assertTrue($svc->send('9123456789', '111111'), "attempt {$i} should still be accepted");
            $this->assertNull($svc->lastRequestId, "attempt {$i} must not expose a malformed id");
        }
    }

    public function test_request_id_is_reset_between_attempts(): void
    {
        $this->msg91Config();
        Http::fake([SmsService::ENDPOINT => Http::sequence()
            ->push(['request_id' => 'abcdef0123456789abcdef01', 'type' => 'success'], 200)
            ->push(['type' => 'error', 'message' => 'insufficient balance'], 200),
        ]);

        $svc = new SmsService();
        $this->assertTrue($svc->send('9123456789', '222222'));
        $this->assertSame('abcdef0123456789abcdef01', $svc->lastRequestId);

        $this->assertFalse($svc->send('9123456789', '222222'));
        $this->assertNull($svc->lastRequestId, 'a failed attempt must not carry the previous id');
        $this->assertSame('provider:insufficient balance', $svc->lastError);
    }

    public function test_provider_error_bodies_and_http_errors_are_failures(): void
    {
        $this->msg91Config();
        Http::fake([SmsService::ENDPOINT => Http::sequence()
            ->push(['type' => 'error', 'message' => 'invalid template id'], 200)
            // A paid send is attempted once, with no automatic retry.
            ->push(['type' => 'error', 'message' => 'IP is not whitelisted', 'code' => 418], 401)
            ->push('', 500)
            ->push('<html><body>Bad Gateway</body></html>', 200),
        ]);

        $svc = new SmsService();

        $this->assertFalse($svc->send('9123456789', '333333'));
        $this->assertSame('provider:invalid template id', $svc->lastError);
        $this->assertNull($svc->lastRequestId);

        $this->assertFalse($svc->send('9123456789', '333333'));
        $this->assertSame('provider:IP is not whitelisted', $svc->lastError);

        // HTTP 500 is retried twice by the client (3 attempts), then reported.
        $this->assertFalse($svc->send('9123456789', '333333'));
        $this->assertSame('provider:http 500', $svc->lastError);

        // Malformed JSON with HTTP 200 must not be treated as success.
        $this->assertFalse($svc->send('9123456789', '333333'));
        $this->assertStringStartsWith('provider:', $svc->lastError);
        $this->assertNull($svc->lastRequestId);
    }

    public function test_sms_test_command_distinguishes_acceptance_from_delivery(): void
    {
        $this->msg91Config();
        Http::fake([SmsService::ENDPOINT => Http::response(['request_id' => '0123456789abcdef01234567', 'type' => 'success'], 200)]);

        $this->artisan('enotary:sms-test', ['mobile' => '9123456789', '--force' => true])
            ->expectsOutputToContain('MSG91 accepted the request for ******6789')
            ->expectsOutputToContain('MSG91 request_id: 0123456789abcdef01234567')
            ->expectsOutputToContain('Delivery to the handset is NOT confirmed')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_sms_test_command_reports_provider_rejection_without_secrets(): void
    {
        $this->msg91Config();
        Http::fake([SmsService::ENDPOINT => Http::response(['type' => 'error', 'message' => 'Authkey has no permission to send an sms.'], 200)]);

        $this->artisan('enotary:sms-test', ['mobile' => '9123456789', '--force' => true])
            ->expectsOutputToContain('MSG91 did not accept the request: provider:Authkey has no permission to send an sms.')
            ->assertFailed();
    }

    public function test_sms_service_transport_exception_returns_false(): void
    {
        config(['services.msg91.auth_key' => 'k', 'services.msg91.template_id' => 't']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $svc = new SmsService();
        $this->assertFalse($svc->send('9123456789', '111111'));
        $this->assertStringStartsWith('transport:', $svc->lastError);
    }

    // ------------------------------------------- private document storage

    public function test_upload_goes_to_private_disk_and_download_is_authorised(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::fake(Document::LEGACY_DISK);

        $owner = $this->user();
        $other = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);
        $otherNotary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);

        $doc = $this->upload($owner);

        Storage::disk(Document::PRIVATE_DISK)->assertExists($doc->original_file);
        Storage::disk(Document::LEGACY_DISK)->assertMissing($doc->original_file);
        $this->assertSame('my-affidavit.pdf', $doc->downloadName());

        // Owner and admin can download; a stranger cannot.
        $this->actingAs($owner)->get("/user/documents/{$doc->id}/download")
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=my-affidavit.pdf');
        $this->actingAs($other)->get("/user/documents/{$doc->id}/download")->assertForbidden();
        $this->actingAs($admin)->get("/admin/documents/{$doc->id}/download")->assertOk();

        // Notary: only once assigned, and only the assigned notary.
        $request = $doc->request;
        $this->actingAs($notary)->get("/notary/request/{$request->id}/documents/{$doc->id}/download")->assertNotFound();
        $request->update(['status' => 'assigned', 'notary_id' => $notary->id]);
        $this->actingAs($notary)->get("/notary/request/{$request->id}/documents/{$doc->id}/download")->assertOk();
        $this->actingAs($otherNotary)->get("/notary/request/{$request->id}/documents/{$doc->id}/download")->assertNotFound();

        // Guests are redirected to the login page.
        auth()->guard('web')->logout();
        $this->flushSession();
        $this->get("/user/documents/{$doc->id}/download")->assertRedirect(route('login'));
    }

    public function test_public_disk_file_is_never_served_until_the_migrate_command_moves_it(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::fake(Document::LEGACY_DISK);

        $owner = $this->user();
        $request = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ]);
        Storage::disk(Document::LEGACY_DISK)->put('documents/old.pdf', 'legacy-bytes');
        $doc = Document::create(['request_id' => $request->id, 'title' => 'Old', 'original_file' => 'documents/old.pdf']);

        // No fallback: a file on the public disk is treated as missing, even for its owner.
        $this->assertNull($doc->storageDisk());
        $this->actingAs($owner)->get("/user/documents/{$doc->id}/download")->assertRedirect(route('user.documents'));

        // Dry run changes nothing.
        $this->artisan('enotary:documents-migrate')->expectsOutputToContain('1 to move')->assertSuccessful();
        Storage::disk(Document::LEGACY_DISK)->assertExists('documents/old.pdf');

        // --apply moves it, verifying size, and the download then works.
        $this->artisan('enotary:documents-migrate', ['--apply' => true])->expectsOutputToContain('Moved 1 of 1')->assertSuccessful();
        Storage::disk(Document::PRIVATE_DISK)->assertExists('documents/old.pdf');
        Storage::disk(Document::LEGACY_DISK)->assertMissing('documents/old.pdf');
        $this->assertSame('legacy-bytes', Storage::disk(Document::PRIVATE_DISK)->get('documents/old.pdf'));
        $this->actingAs($owner)->get("/user/documents/{$doc->id}/download")->assertOk();
    }

    public function test_delete_removes_file_and_is_refused_once_under_review(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::fake(Document::LEGACY_DISK);

        $owner = $this->user();
        $doc = $this->upload($owner);
        $path = $doc->original_file;

        // Under review: refused, file kept.
        $doc->request->update(['status' => 'assigned']);
        $this->actingAs($owner)->delete("/user/documents/{$doc->id}")->assertSessionHas('error');
        Storage::disk(Document::PRIVATE_DISK)->assertExists($path);

        // Pending: deleted together with the file.
        $doc->request->update(['status' => 'pending']);
        $this->actingAs($owner)->delete("/user/documents/{$doc->id}")->assertSessionHas('status');
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
        Storage::disk(Document::PRIVATE_DISK)->assertMissing($path);
    }

    public function test_admin_removal_needs_a_reason_and_keeps_the_file_for_the_retention_period(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Storage::fake(Document::LEGACY_DISK);

        $owner = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $doc = $this->upload($owner);

        $this->actingAs($admin)->delete("/admin/documents/{$doc->id}")->assertSessionHasErrors('reason');
        $this->assertNotSoftDeleted('documents', ['id' => $doc->id]);

        $this->actingAs($admin)->delete("/admin/documents/{$doc->id}", ['reason' => 'Uploaded in error'])->assertRedirect(route('admin.documents.index'));
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
        Storage::disk(Document::PRIVATE_DISK)->assertExists($doc->original_file);
    }
}
