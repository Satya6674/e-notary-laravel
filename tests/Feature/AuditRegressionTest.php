<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for defects found in the 2026-10-28 deep audit.
 */
class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The custom login/register endpoints are called by page JavaScript with
        // a header token; CSRF verification is exercised separately in the browser
        // pass, so it is disabled here to test the controller behaviour directly.
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Notification::fake();
    }

    /** Registration with a duplicate email must return 422, not 500. */
    public function test_registration_validation_error_returns_422(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'mobile' => '9111111111',
            'password' => Hash::make('Secret123!'),
            'role' => 'public',
        ]);

        $response = $this->postJson('/custom-register', [
            'name' => 'New User',
            'email' => 'taken@example.com',
            'mobile' => '9222222222',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
            'role' => 'public', 'accept_terms' => '1',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    /** Valid registration still succeeds and creates the user. */
    public function test_valid_registration_succeeds(): void
    {
        $response = $this->postJson('/custom-register', [
            'name' => 'Fresh User',
            'email' => 'fresh@example.com',
            'mobile' => '9333333333',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
            'role' => 'public', 'accept_terms' => '1',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('users', ['email' => 'fresh@example.com']);
    }

    /** Approving a notary sets the admin-approval timestamp; rejecting clears it. */
    public function test_notary_approve_and_reject_toggle_approval(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'mobile' => '9444444444',
            'password' => Hash::make('Secret123!'), 'role' => 'admin', 'email_verified_at' => now(),
        ]);
        $notary = User::create([
            'name' => 'Notary', 'email' => 'notary@example.com', 'mobile' => '9555555555',
            'password' => Hash::make('Secret123!'), 'role' => 'notary', 'email_verified_at' => now(),
        ]);

        $this->assertNull($notary->notary_approved_at);

        $this->actingAs($admin)->post("/admin/notaries/{$notary->id}/approve")
            ->assertRedirect(route('admin.notaries.index'));
        $this->assertNotNull($notary->fresh()->notary_approved_at);

        $this->actingAs($admin)->post("/admin/notaries/{$notary->id}/reject", ['reason' => 'Approval withdrawn in this test.'])
            ->assertRedirect(route('admin.notaries.index'));
        $this->assertNull($notary->fresh()->notary_approved_at);
    }

    /** Document upload rejects disallowed file types and accepts allowed ones. */
    public function test_document_upload_enforces_mime_whitelist(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::create([
            'name' => 'Uploader', 'email' => 'up@example.com', 'mobile' => '9666666666',
            'password' => Hash::make('Secret123!'), 'role' => 'public', 'email_verified_at' => now(), 'mobile_verified_at' => now(),
        ]);

        $base = [
            'title' => 'Doc',
            'document_type' => 'affidavit',
            'description' => 'test',
        ];

        // A .txt file is not an allowed document type.
        $this->actingAs($user)->post('/user/upload', $base + [
            'file' => UploadedFile::fake()->create('note.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');

        // A PDF is accepted.
        $this->actingAs($user)->post('/user/upload', $base + [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('user.documents'));

        $this->assertDatabaseHas('documents', ['title' => 'Doc']);
    }
}
