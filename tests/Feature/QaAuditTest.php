<?php

namespace Tests\Feature;

use App\Console\Commands\MailTest;
use App\Mail\TestMail;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end QA pass (2026-10-29): registration/login edge cases, guest and
 * role gates, IDOR checks on documents and requests, upload content sniffing,
 * mass-assignment attempts, CSRF enforcement, and the mail test tooling.
 *
 * Everything runs on the in-memory SQLite database with faked mail and
 * notifications; nothing here can reach SMTP, MSG91 or the real database.
 */
class QaAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Temp files created by realUpload(); removed in tearDown even when a test fails. */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    /**
     * A real upload backed by a temp file. UploadedFile::fake() reports the
     * MIME type from the file NAME, which would let a disguised file through
     * in tests only; this helper makes the "mimes" rule sniff real content,
     * exactly as it does for a browser upload.
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'enotary-qa-');
        $this->assertNotFalse($path, 'could not create a temp file');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function user(array $overrides = []): User
    {
        static $i = 0;
        $i++;

        return User::create(array_merge([
            'name' => "QA User {$i}",
            'email' => "qa{$i}@example.com",
            'mobile' => '9000000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'password' => Hash::make('Secret123!'),
            'role' => 'public',
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
        ], $overrides));
    }

    private function requestWithDocument(User $owner, array $overrides = []): array
    {
        $request = NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(),
            'user_id' => $owner->id,
            'document_type' => 'affidavit',
            'purpose' => 'QA',
            'status' => NotaryRequest::STATUS_PENDING,
        ], $overrides));

        $path = 'documents/'.uniqid('qa_', true).'.pdf';
        Storage::disk(Document::PRIVATE_DISK)->put($path, '%PDF-1.4 qa');

        $document = Document::create([
            'request_id' => $request->id,
            'title' => 'QA Document',
            'original_file' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 11,
        ]);

        return [$request, $document];
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'mobile' => '9123456789',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role' => 'public',
            'accept_terms' => '1',
        ], $overrides);
    }

    // ------------------------------------------------ A. registration & login

    public function test_registration_rejects_each_invalid_field_with_422(): void
    {
        $this->user(['email' => 'taken@example.com', 'mobile' => '9555555555']);

        $cases = [
            'missing name'      => ['name' => ''],
            'invalid email'     => ['email' => 'not-an-email'],
            'short password'    => ['password' => 'short'],
            'bad mobile'        => ['mobile' => '12345'],
            'duplicate email'   => ['email' => 'taken@example.com'],
            'duplicate mobile'  => ['mobile' => '+91 95555 55555'], // normalised to 9555555555
            'admin role'        => ['role' => 'admin'],
            'unknown role'      => ['role' => 'superuser'],
        ];

        foreach ($cases as $label => $override) {
            // The register limiter allows 5/min per IP (covered by its own test);
            // reset it so every case below exercises validation, not throttling.
            Cache::flush();

            $response = $this->postJson('/custom-register', $this->registration($override));
            $response->assertStatus(422, $label);
            $response->assertJson(['success' => false]);
        }

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
        Notification::assertNothingSent();
    }

    public function test_registration_ignores_injected_verification_and_approval_fields(): void
    {
        $response = $this->postJson('/custom-register', $this->registration([
            'mobile' => '+91 91234 56789',
            'email_verified_at' => now()->toDateTimeString(),
            'notary_approved_at' => now()->toDateTimeString(),
            'role' => 'notary',
        ]));

        $response->assertOk()->assertJson(['success' => true]);

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame('9123456789', $user->mobile, 'mobile is stored normalised');
        $this->assertNull($user->email_verified_at, 'client cannot pre-verify the email');
        $this->assertNull($user->notary_approved_at, 'client cannot self-approve a notary');
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_login_accepts_email_or_formatted_mobile_and_rejects_bad_password(): void
    {
        $user = $this->user(['email' => 'login@example.com', 'mobile' => '9876543210']);

        $this->postJson('/custom-login', ['mobile' => 'login@example.com', 'password' => 'wrong'])
            ->assertStatus(401)->assertJson(['success' => false]);
        $this->assertGuest();

        $this->postJson('/custom-login', ['mobile' => 'login@example.com', 'password' => 'Secret123!'])
            ->assertOk()->assertJson(['success' => true, 'redirect' => route('dashboard')]);
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->postJson('/custom-login', ['mobile' => '9876543210', 'password' => 'Secret123!'])
            ->assertOk()->assertJson(['success' => true]);

        // Registration normalises "+91 98765 43210" to 9876543210, so login must too.
        auth()->logout();
        $this->postJson('/custom-login', ['mobile' => '+91 98765 43210', 'password' => 'Secret123!'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_validation_errors_are_json_when_json_is_requested(): void
    {
        $this->postJson('/custom-login', ['mobile' => '', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile', 'password']);
    }

    public function test_login_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/custom-login', ['mobile' => 'nobody@example.com', 'password' => 'x'])->assertStatus(401);
        }

        $this->postJson('/custom-login', ['mobile' => 'nobody@example.com', 'password' => 'x'])->assertStatus(429);
    }

    public function test_unverified_login_goes_to_notice_and_logout_ends_the_session(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $this->postJson('/custom-login', ['mobile' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()->assertJson(['redirect' => route('verification.notice')]);

        $this->get('/dashboard')->assertRedirect(route('verification.notice'));

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_from_every_protected_area(): void
    {
        foreach (['/dashboard', '/user/upload', '/user/documents', '/user/profile', '/email/verify',
                  '/admin/dashboard', '/admin/users', '/notary/dashboard', '/notary/requests'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }

        $this->postJson('/mobile/verification-code')->assertStatus(401);
    }

    public function test_csrf_token_is_required_on_state_changing_routes(): void
    {
        // ValidateCsrfToken skips itself while the env is "testing"; run this
        // test as "local" so the real check executes (no token = 419).
        $this->app['env'] = 'local';

        $this->withMiddleware(VerifyCsrfToken::class)
            ->post('/custom-login', ['mobile' => 'a@example.com', 'password' => 'x'])
            ->assertStatus(419);

        $this->post('/logout')->assertStatus(419);
        $this->post('/contact', [])->assertStatus(419);
    }

    public function test_storage_serve_route_refuses_unsigned_requests(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/secret.pdf', '%PDF-1.4 secret');

        $response = $this->get('/storage/documents/secret.pdf');

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    // ------------------------------------------------------- D. roles & IDOR

    public function test_role_gates_block_cross_area_access(): void
    {
        $public = $this->user();
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);
        $admin  = $this->user(['role' => 'admin']);

        $this->actingAs($public)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($public)->get('/notary/dashboard')->assertForbidden();
        $this->actingAs($notary)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($notary)->get('/notary/dashboard')->assertOk();
        $this->actingAs($admin)->get('/notary/dashboard')->assertForbidden();
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
    }

    public function test_users_cannot_read_download_or_delete_other_users_documents_or_requests(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = $this->user();
        $other = $this->user();
        [$request, $document] = $this->requestWithDocument($owner);

        $this->actingAs($other)->get("/user/documents/{$document->id}")->assertForbidden();
        $this->actingAs($other)->get("/user/documents/{$document->id}/download")->assertForbidden();
        $this->actingAs($other)->delete("/user/documents/{$document->id}")->assertForbidden();
        $this->actingAs($other)->get("/user/requests/{$request->id}")->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->original_file);

        $this->actingAs($owner)->get("/user/documents/{$document->id}")->assertOk();
        $this->actingAs($owner)->get("/user/requests/{$request->id}")->assertOk();
        $this->actingAs($owner)->get("/user/documents/{$document->id}/download")
            ->assertOk()->assertHeader('content-disposition');
    }

    public function test_notary_can_only_download_documents_of_requests_assigned_to_them(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner  = $this->user();
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);
        $otherNotary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);

        [$mine, $myDoc] = $this->requestWithDocument($owner, [
            'status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $notary->id,
        ]);
        [$theirs, $theirDoc] = $this->requestWithDocument($owner, [
            'status' => NotaryRequest::STATUS_ASSIGNED, 'notary_id' => $otherNotary->id,
        ]);

        $this->actingAs($notary)->get("/notary/request/{$mine->id}/documents/{$myDoc->id}/download")->assertOk();
        // Mixing an assigned request id with a foreign document id must not work.
        $this->actingAs($notary)->get("/notary/request/{$mine->id}/documents/{$theirDoc->id}/download")->assertNotFound();
        $this->actingAs($notary)->get("/notary/request/{$theirs->id}/documents/{$theirDoc->id}/download")->assertNotFound();
        $this->actingAs($notary)->get("/notary/request/{$theirs->id}")->assertNotFound();
        $this->actingAs($notary)->post("/notary/request/{$theirs->id}/approve")->assertNotFound();
    }

    public function test_admin_cannot_delete_or_demote_their_own_account_and_cannot_approve_non_notaries(): void
    {
        $admin  = $this->user(['role' => 'admin']);
        $public = $this->user();

        $this->actingAs($admin)->from('/admin/users')->delete("/admin/users/{$admin->id}")
            ->assertRedirect('/admin/users')->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);

        $this->actingAs($admin)->from("/admin/users/{$admin->id}/edit")->put("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'mobile' => $admin->mobile, 'role' => 'public',
        ])->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);

        $this->actingAs($admin)->from('/admin/notaries')->post("/admin/notaries/{$public->id}/approve")
            ->assertSessionHas('error');
        $this->assertNull($public->fresh()->notary_approved_at);
    }

    public function test_profile_update_cannot_escalate_role_and_email_change_requires_reverification(): void
    {
        $user = $this->user(['email' => 'before@example.com']);

        $this->actingAs($user)->put('/user/profile', [
            'name' => 'Still Me', 'email' => 'before@example.com', 'mobile' => $user->mobile, 'role' => 'admin',
        ])->assertRedirect(route('user.profile'));
        $this->assertSame('public', $user->fresh()->role);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->actingAs($user)->put('/user/profile', [
            'name' => 'Still Me', 'email' => 'after@example.com', 'mobile' => $user->mobile,
        ])->assertSessionHasErrors('current_password');
        $this->assertSame('before@example.com', $user->fresh()->email);

        $this->actingAs($user)->put('/user/profile', [
            'name' => 'Still Me', 'email' => 'after@example.com', 'mobile' => $user->mobile, 'current_password' => 'Secret123!',
        ])->assertRedirect(route('verification.notice'));

        $fresh = $user->fresh();
        $this->assertSame('after@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        Notification::assertSentTo($fresh, VerifyEmailNotification::class);
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_notary_password_change_requires_the_current_password(): void
    {
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);

        $this->actingAs($notary)->from('/notary/profile')->post('/notary/profile/password', [
            'current_password' => 'wrong', 'password' => 'Newsecret1!', 'password_confirmation' => 'Newsecret1!',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('Secret123!', $notary->fresh()->password));

        $this->actingAs($notary)->from('/notary/profile')->post('/notary/profile/password', [
            'current_password' => 'Secret123!', 'password' => 'Newsecret1!', 'password_confirmation' => 'Newsecret1!',
        ])->assertSessionHas('success');
        $this->assertTrue(Hash::check('Newsecret1!', $notary->fresh()->password));
    }

    public function test_otp_reset_steps_cannot_be_skipped_without_a_session(): void
    {
        $this->get('/reset-password-form')->assertRedirect(route('password.request'));
        $this->post('/reset-password-form', ['password' => 'Newsecret1!', 'password_confirmation' => 'Newsecret1!'])
            ->assertRedirect(route('password.request'));
        $this->post('/verify-otp', ['otp' => '123456'])->assertRedirect(route('password.request'));
    }

    // ------------------------------------------------------------ E. uploads

    public function test_upload_rejects_disguised_oversized_and_missing_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->user();
        $base = ['title' => 'Doc', 'document_type' => 'affidavit', 'description' => 'QA upload'];

        // Disguised files: the NAME says PDF but the CONTENT is not. The sniffed
        // type is asserted first, proving the "mimes" rule is judging content.
        $phpAsPdf  = $this->realUpload('evil.pdf', "<?php echo 'x'; ?>");
        $htmlAsPdf = $this->realUpload('page.pdf', '<!doctype html><html><script>alert(1)</script></html>');
        $this->assertSame('text/x-php', $phpAsPdf->getMimeType());
        $this->assertSame('text/html', $htmlAsPdf->getMimeType());
        $this->assertSame('application/pdf', $this->realUpload('ok.pdf', "%PDF-1.4\n")->getMimeType());

        $bad = [
            'no file'            => [],
            'php as pdf'         => ['file' => $phpAsPdf],
            'html as pdf'        => ['file' => $htmlAsPdf],
            'svg image'          => ['file' => $this->realUpload('img.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>')],
            'executable'         => ['file' => $this->realUpload('run.exe', "MZ\x90\x00\x03\x00\x00\x00binary")],
            'php extension'      => ['file' => $this->realUpload('shell.php', "%PDF-1.4\n")],
            'over 10 MB'         => ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')],
            'bad document type'  => ['file' => $this->realUpload('ok.pdf', "%PDF-1.4\n"), 'document_type' => 'passport'],
        ];

        foreach ($bad as $label => $override) {
            $response = $this->actingAs($user)->from('/user/upload')->post('/user/upload', array_merge($base, $override));
            $this->assertSame(url('/user/upload'), $response->headers->get('Location'), "case '{$label}' must be rejected");
            $response->assertSessionHasErrors(isset($override['document_type']) ? 'document_type' : 'file');
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('notary_requests', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($user)->post('/user/upload', $base + [
            'file' => $this->realUpload('real.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"),
        ])->assertRedirect(route('user.documents'));

        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('documents'));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'new uploads never land on the public disk');
    }

    // ------------------------------------------- G. XSS, SQLi, error handling

    public function test_user_supplied_text_is_escaped_on_every_page_that_shows_it(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payload = '<script>alert("qa")</script><img src=x onerror=alert(1)>';
        $owner = $this->user(['name' => 'Owner '.$payload]);
        $admin = $this->user(['role' => 'admin']);
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);

        [$request, $document] = $this->requestWithDocument($owner, [
            'purpose' => 'Purpose '.$payload,
            'remarks' => 'Remarks '.$payload,
            'status' => NotaryRequest::STATUS_ASSIGNED,
            'notary_id' => $notary->id,
        ]);
        $document->update(['title' => 'Title '.$payload]);

        $this->post('/contact', [
            'name' => 'Sender '.$payload, 'email' => 'sender@example.com',
            'subject' => 'General Inquiry', 'message' => 'Message body '.$payload,
        ])->assertRedirect();

        $pages = [
            [$owner,  '/user/documents'],
            [$owner,  "/user/documents/{$document->id}"],
            [$owner,  '/user/requests'],
            [$owner,  "/user/requests/{$request->id}"],
            [$owner,  '/dashboard'],
            [$notary, '/notary/dashboard'],
            [$notary, '/notary/requests'],
            [$notary, "/notary/request/{$request->id}"],
            [$admin,  '/admin/dashboard'],
            [$admin,  '/admin/documents'],
            [$admin,  "/admin/documents/{$document->id}"],
            [$admin,  '/admin/requests'],
            [$admin,  "/admin/requests/{$request->id}"],
            [$admin,  '/admin/users'],
            [$admin,  "/admin/users/{$owner->id}"],
            [$admin,  "/admin/users/{$owner->id}/edit"],
            [$admin,  '/admin/messages'],
        ];

        foreach ($pages as [$viewer, $uri]) {
            $html = $this->actingAs($viewer)->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert("qa")', $html, "raw script leaked on {$uri}");
            $this->assertStringNotContainsString('<img src=x onerror', $html, "raw img tag leaked on {$uri}");
        }

        // The pages that display the text must show it HTML-escaped, not drop it.
        foreach ([[$owner, '/user/documents'], [$admin, "/admin/users/{$owner->id}"], [$admin, '/admin/messages']] as [$viewer, $uri]) {
            $this->actingAs($viewer)->get($uri)->assertSee('&lt;script&gt;alert(&quot;qa&quot;)&lt;/script&gt;', false);
        }
    }

    public function test_search_and_filter_inputs_cannot_break_queries(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $this->user(['name' => 'Needle Person']);

        $injections = ["' OR 1=1 --", "%' UNION SELECT 1,2,3 --", '%', '_', "\\", "'; DROP TABLE users; --"];

        foreach ($injections as $term) {
            foreach (['/admin/users', '/admin/notaries', '/admin/requests', '/admin/documents', '/admin/messages', '/admin/audit-logs'] as $uri) {
                $this->actingAs($admin)->get($uri.'?search='.urlencode($term).'&status='.urlencode($term).'&role='.urlencode($term))
                    ->assertOk();
            }
        }

        $this->assertDatabaseCount('users', 2);
        $this->actingAs($admin)->get('/admin/users?search=Needle')->assertOk()->assertSee('Needle Person');
        $this->actingAs($admin)->get('/admin/users?search=NoSuchPerson')->assertOk()->assertDontSee('Needle Person');
    }

    public function test_unknown_or_malformed_ids_give_404_not_500(): void
    {
        $user = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $notary = $this->user(['role' => 'notary', 'notary_approved_at' => now()]);

        foreach (['/user/documents/999999', '/user/documents/abc', '/user/documents/999999/download',
                  '/user/requests/999999', '/user/requests/%27'] as $uri) {
            $this->actingAs($user)->get($uri)->assertNotFound();
        }
        $this->actingAs($user)->delete('/user/documents/999999')->assertNotFound();

        foreach (['/admin/users/999999', '/admin/requests/999999', '/admin/documents/999999', '/admin/documents/abc/download'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertNotFound();
        }
        $this->actingAs($admin)->post('/admin/requests/999999/approve')->assertNotFound();
        $this->actingAs($admin)->post('/admin/notaries/999999/approve')->assertNotFound();

        $this->actingAs($notary)->get('/notary/request/999999')->assertNotFound();
        $this->actingAs($notary)->get('/notary/request/abc/documents/1/download')->assertNotFound();
    }

    // ---------------------------------------------------------- F. mail tooling

    public function test_verification_mail_renders_the_code_only_without_a_link_or_secrets(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $html = (new VerifyEmailNotification('482913', now()->addMinutes(10)))->toMail($user)->render();

        $this->assertStringContainsString('482913', $html);
        $this->assertStringNotContainsString('/email/verify/', $html);
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString($user->password, $html);
        $this->assertStringNotContainsString($user->mobile, $html);
    }

    public function test_mail_test_command_sends_exactly_one_message_to_the_given_address_only(): void
    {
        // The in-memory array mailer, not Mail::fake(): the command reports what the transport really
        // returned, and a fake returns nothing, which the command rightly reports as not sent.
        $sent = fn () => collect(app('mailer')->getSymfonyTransport()->messages());

        $this->artisan('enotary:mail-test', ['to' => 'not-an-address'])->assertExitCode(MailTest::FAILURE);
        $this->assertCount(0, $sent());

        $this->artisan('enotary:mail-test', ['to' => 'qa-recipient@example.com', '--force' => true])
            ->expectsOutputToContain('ACCEPTED by the mail transport (array)')
            ->assertExitCode(MailTest::SUCCESS);

        $this->assertCount(1, $sent());
        $this->assertSame(['qa-recipient@example.com'], array_map(fn ($a) => $a->getAddress(), $sent()->first()->getEnvelope()->getRecipients()));
        $this->assertSame((new TestMail)->envelope()->subject, $sent()->first()->getOriginalMessage()->getSubject());

        $html = (new TestMail)->render();
        $this->assertStringContainsString('SMTP test message', $html);
    }

    public function test_mail_status_command_masks_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.username' => 'sender@example.com',
            'mail.mailers.smtp.password' => 'super-secret-app-pw',
            'mail.from.address' => 'sender@example.com',
        ]);

        $this->artisan('enotary:mail-status')
            ->expectsOutputToContain('(set, 19 chars)')
            ->expectsOutputToContain('se****@example.com')
            ->doesntExpectOutputToContain('super-secret-app-pw')
            ->assertExitCode(0);
    }
}
