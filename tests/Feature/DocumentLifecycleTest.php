<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\ServiceCatalogue;
use App\Support\PdfRenderer;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BR-008 end to end. Every journey starts with a real upload through the routes a citizen
 * uses, follows the application through review, and reads the result the way an outsider
 * does: through the address inside the certificate's QR code.
 *
 * Evidence level: real controllers, database and file bytes on a faked disk. Notifications
 * are faked. scripts/Test-MariaDB.ps1 runs this class on MariaDB too.
 */
class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $citizen;

    private User $notary;

    private User $admin;

    private string $lastPdf = '';

    public function remember(string $html): string
    {
        return $this->lastPdf = $html;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Storage::fake('local');
        Notification::fake();

        // Finished PDF text is encoded glyphs, so keep the source each PDF was made from.
        $test = $this;
        $this->app->instance(PdfRenderer::class, new class($test) extends PdfRenderer {
            public function __construct(private $test)
            {
            }

            public function html(string $view, array $data = []): string
            {
                return $this->test->remember(parent::html($view, $data));
            }
        });

        $this->citizen = $this->user('public');
        $this->notary = $this->user('notary');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        static $i = 100;
        $i++;

        return User::create([
            'name' => "Person {$i}", 'email' => "l{$i}@example.test", 'mobile' => '9800000'.$i,
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function pdf(string $text = 'original'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("{$text}.pdf", "%PDF-1.4\n{$text}\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    /** Written directly, so no audit entry exists for it. */
    private function application(string $status): NotaryRequest
    {
        return NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $this->citizen->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => $status,
        ]);
    }

    /** Original submission, through the citizen's upload route, then assignment. */
    private function submitted(): NotaryRequest
    {
        $this->actingAs($this->citizen)->post('/user/upload', [
            'title' => 'Sale deed', 'document_type' => 'sale_deed', 'description' => 'For a house', 'file' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $application = NotaryRequest::where('user_id', $this->citizen->id)->latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/assign", ['notary_id' => $this->notary->id]);

        return $application->fresh();
    }

    private function decide(NotaryRequest $application, string $decision): TestResponse
    {
        return $this->actingAs($this->notary)->from("/notary/request/{$application->id}")
            ->post("/notary/request/{$application->id}/{$decision}", ['reason' => 'Reviewed carefully']);
    }

    private function completed(): NotaryRequest
    {
        $application = $this->submitted();
        $this->decide($application, 'approve');
        $this->decide($application, 'complete');

        return $application->fresh();
    }

    private function correct(NotaryRequest $application, string $text = 'corrected'): void
    {
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Fixed', 'file' => $this->pdf($text)])
            ->assertSessionHasNoErrors();
    }

    private function current(NotaryRequest $application): Document
    {
        return $application->currentDocuments()->firstOrFail();
    }

    /** The address printed inside the QR code, opened by someone signed out. */
    private function scan(Document $document): TestResponse
    {
        Auth::logout();

        $response = $this->get(parse_url($document->verificationUrl(), PHP_URL_PATH));
        $response->assertOk();

        return $response;
    }

    private function assertCertificateBlocked(NotaryRequest $application): void
    {
        foreach ([$this->citizen, $this->notary, $this->admin] as $viewer) {
            $this->actingAs($viewer)->get("/applications/{$application->id}/certificate")->assertNotFound();
            $this->actingAs($viewer)->get("/applications/{$application->id}/certificate.pdf")->assertNotFound();
        }
    }

    private function assertCertificateOpen(NotaryRequest $application, Document $document): void
    {
        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertOk()->assertSee($document->verification_code);
        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($document->verification_code, $this->lastPdf);
    }

    /** Summary, review and receipt read as completed, so each must warn. None may vouch for the file. */
    private function assertGeneratedDocumentsWarn(NotaryRequest $application): void
    {
        foreach (['summary', 'review-report', 'receipt'] as $name) {
            $this->actingAs($this->citizen)->get("/applications/{$application->id}/{$name}.pdf")->assertOk();
            $this->assertStringContainsString('no longer valid', $this->lastPdf, $name);
        }

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/summary.pdf")->assertOk();
        $this->assertStringNotContainsString('File uploaded', $this->lastPdf);
        $this->assertStringContainsString('File could not be confirmed', $this->lastPdf);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/review-report.pdf")->assertOk();
        $this->assertStringNotContainsString('No further action', $this->lastPdf);

        foreach ([[$this->citizen, "/user/requests/{$application->id}"], [$this->notary, "/notary/request/{$application->id}"], [$this->admin, "/admin/requests/{$application->id}"]] as [$viewer, $page]) {
            $this->actingAs($viewer)->get($page)->assertOk()->assertSee('A certificate on this application is no longer valid');
        }
    }

    // ---- Original submission

    public function test_an_original_submission_is_version_one_current_and_not_yet_certified(): void
    {
        $document = $this->current($this->submitted());

        $this->assertSame(1, (int) $document->version);
        $this->assertNull($document->replaces_document_id);
        $this->assertNull($document->verification_code);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($document->original_file)), $document->file_hash);
        $this->assertNull($document->lifecycleStatus());
    }

    public function test_a_completed_application_certifies_the_file_and_the_qr_code_confirms_it(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $this->assertCertificateOpen($application, $document);
        $this->assertSame('certified', $document->lifecycleStatus());
        $this->scan($document)->assertSee('This record exists and is current.')->assertSee($document->document_number);
    }

    public function test_the_address_in_the_certificate_is_the_one_that_gets_checked(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")
            ->assertSee('data-qr="'.$document->verificationUrl().'"', false);

        $this->assertStringEndsWith('/verify/'.$document->verification_code, $document->verificationUrl());
    }

    // ---- Correction and replacement

    public function test_a_correction_after_completion_moves_every_surface_to_the_new_version(): void
    {
        $application = $this->completed();
        $old = $this->current($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Wrong file certified']);
        $this->correct($application);

        $this->scan($old)->assertSee('This record was replaced by a newer version and is no longer current.');

        $this->decide($application, 'approve');
        $this->decide($application, 'complete');

        $new = $this->current($application);
        $this->assertSame(2, (int) $new->version);
        $this->assertNotSame($old->verification_code, $new->verification_code);
        $this->assertCertificateOpen($application, $new);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertDontSee($old->verification_code);
        $this->scan($new)->assertSee('This record exists and is current.');
        $this->scan($old)->assertSee('This record was replaced by a newer version');
        $this->assertSame('superseded', $old->fresh()->lifecycleStatus());
    }

    public function test_several_corrections_leave_one_current_version_and_a_full_chain(): void
    {
        $application = $this->submitted();

        foreach (['second', 'third'] as $text) {
            $this->decide($application, 'correct');
            $this->correct($application, $text);
        }

        $versions = $application->documents()->orderBy('version')->get();

        $this->assertSame([1, 2, 3], $versions->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, $versions->whereNull('superseded_at')->count());
        $this->assertSame($versions[2]->id, $this->current($application)->id);
        $this->assertSame($versions[1]->id, (int) $versions[2]->replaces_document_id);
        $this->assertSame($versions[0]->id, (int) $versions[1]->replaces_document_id);
    }

    // ---- Rejection

    public function test_a_rejected_application_shows_rejected_in_its_history_and_issues_nothing(): void
    {
        $application = $this->submitted();
        $this->decide($application, 'reject');

        $document = $this->current($application);

        $this->assertNull($document->verification_code);
        $this->assertSame('rejected', $document->lifecycleStatus());
        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertNotFound();
        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertSee('Rejected');
    }

    public function test_rejecting_after_a_correction_keeps_both_statuses_clear(): void
    {
        $application = $this->submitted();
        $old = $this->current($application);
        $this->decide($application, 'correct');
        $this->correct($application);
        $this->decide($application, 'reject');

        $this->assertSame('superseded', $old->fresh()->lifecycleStatus());
        $this->assertSame('rejected', $this->current($application)->lifecycleStatus());
    }

    // ---- Revocation

    public function test_rejecting_a_completed_application_revokes_every_surface(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'Issued in error']);

        $this->assertCertificateBlocked($application);
        $this->scan($document)->assertSee('This record has been revoked.')->assertDontSee('This record exists and is current.');
        $this->assertSame('revoked', $document->fresh()->lifecycleStatus());
    }

    public function test_reopening_shows_revoked_until_it_is_completed_again(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Check again']);

        $this->assertSame('revoked', $document->fresh()->lifecycleStatus());
        $this->scan($document)->assertSee('This record has been revoked.');
        $this->assertCertificateBlocked($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Fine after all']);
        $this->decide($application, 'complete');

        $this->assertSame('certified', $document->fresh()->lifecycleStatus());
        $this->scan($document)->assertSee('This record exists and is current.');
        $this->assertCertificateOpen($application, $document->fresh());
    }

    // ---- Missing and tampered files

    public function test_a_tampered_file_invalidates_every_surface_and_is_recorded(): void
    {
        $application = $this->completed();
        $document = $this->current($application);
        $this->assertCertificateOpen($application, $document);

        Storage::disk('local')->put($document->original_file, 'tampered bytes');

        $this->assertCertificateBlocked($application);
        $this->assertGeneratedDocumentsWarn($application);
        $this->scan($document)->assertSee('This record cannot be confirmed')->assertDontSee('This record exists and is current.');
        $this->assertSame('integrity_failed', $document->fresh()->lifecycleStatus());
        $this->assertGreaterThan(0, AuditLog::where('action', 'certificate.blocked')->count());
    }

    public function test_a_missing_file_invalidates_every_surface(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        Storage::disk('local')->delete($document->original_file);

        $this->assertCertificateBlocked($application);
        $this->assertGeneratedDocumentsWarn($application);
        $this->scan($document)->assertSee('This record cannot be confirmed');
        $this->assertSame('integrity_failed', $document->fresh()->lifecycleStatus());
    }

    public function test_restoring_the_exact_file_brings_the_certificate_back(): void
    {
        $application = $this->completed();
        $document = $this->current($application);
        $original = Storage::disk('local')->get($document->original_file);

        Storage::disk('local')->put($document->original_file, 'tampered bytes');
        $this->scan($document)->assertSee('This record cannot be confirmed');

        Storage::disk('local')->put($document->original_file, $original);
        $this->scan($document)->assertSee('This record exists and is current.');
    }

    public function test_a_certificate_button_is_not_offered_for_a_file_that_failed(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $page = fn () => $this->actingAs($this->citizen)->get("/user/requests/{$application->id}");

        $page()->assertSee(route('applications.certificate', $application))->assertSee(route('applications.certificate-pdf', $application));

        Storage::disk('local')->put($document->original_file, 'tampered bytes');

        $page()->assertOk()->assertDontSee(route('applications.certificate', $application))
            ->assertDontSee(route('applications.certificate-pdf', $application));
    }

    public function test_one_bad_file_withholds_only_its_own_certificate_and_says_so(): void
    {
        $application = $this->submitted();
        $path = 'documents/second.pdf';
        Storage::disk('local')->put($path, 'second file');
        $second = Document::create(['request_id' => $application->id, 'title' => 'Identity proof', 'original_file' => $path, 'file_hash' => hash('sha256', 'second file')]);
        $this->decide($application, 'approve');
        $this->decide($application, 'complete');

        Storage::disk('local')->put($path, 'tampered bytes');
        $good = $application->documents()->whereKeyNot($second->id)->firstOrFail();

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertOk()
            ->assertSee($good->verification_code)->assertDontSee($second->fresh()->verification_code)
            ->assertSee('Some certificates on this application are no longer valid');

        // The other surfaces warn too, although one certificate still stands.
        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertSee('A certificate on this application is no longer valid');
        $this->actingAs($this->citizen)->get("/applications/{$application->id}/summary.pdf")->assertOk();
        $this->assertStringContainsString('no longer valid', $this->lastPdf);
    }

    public function test_the_summary_does_not_vouch_for_a_service_document_that_failed(): void
    {
        $service = app(ServiceCatalogue::class)->all()->first(fn ($s) => collect($s['documents'])->contains('required', true));
        $key = collect($service['documents'])->firstWhere('required', true)['key'];

        $application = $this->submitted();
        $application->update(['service_slug' => $service['slug']]);
        $document = $this->current($application);
        $document->update(['requirement_key' => $key]);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/summary.pdf")->assertOk();
        $this->assertStringContainsString('File uploaded', $this->lastPdf);

        Storage::disk('local')->delete($document->original_file);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/summary.pdf")->assertOk();
        $this->assertStringNotContainsString('File uploaded', $this->lastPdf);
        $this->assertStringContainsString('File could not be confirmed', $this->lastPdf);
    }

    public function test_a_blocked_certificate_reveals_nothing_to_people_with_no_access(): void
    {
        $application = $this->completed();
        Storage::disk('local')->put($this->current($application)->original_file, 'tampered bytes');

        $this->actingAs($this->user('public'))->get("/applications/{$application->id}/certificate")->assertForbidden();
        $this->actingAs($this->user('public'))->get("/applications/{$application->id}/certificate.pdf")->assertForbidden();
    }

    // ---- A valid current document is required to complete

    public function test_completion_is_refused_for_a_changed_or_missing_current_file(): void
    {
        foreach (['changed', 'missing'] as $fault) {
            $application = $this->submitted();
            $document = $this->current($application);
            $this->decide($application, 'approve');

            $fault === 'changed'
                ? Storage::disk('local')->put($document->original_file, 'tampered bytes')
                : Storage::disk('local')->delete($document->original_file);

            $this->decide($application, 'complete')->assertSessionHas('error', fn ($message) => str_contains($message, 'integrity check'));

            $this->assertSame('approved', $application->fresh()->status);
            $this->assertNull($document->fresh()->verification_code);
        }
    }

    public function test_an_admin_cannot_complete_without_a_valid_current_document_either(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Fine']);
        Storage::disk('local')->delete($document->original_file);

        $this->actingAs($this->admin)->from('/admin/requests')->post("/admin/requests/{$application->id}/complete", ['reason' => 'Done'])
            ->assertSessionHas('error');

        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_after_the_last_version_is_removed_no_certificate_can_be_issued(): void
    {
        $application = $this->submitted();
        $this->decide($application, 'correct');
        $this->correct($application);
        $latest = $this->current($application);

        // No route can do this now. The completion gate must hold even so.
        $latest->delete();
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Fine']);
        $this->decide($application, 'complete')->assertSessionHas('error', fn ($message) => str_contains($message, 'no current document'));

        $this->assertSame('approved', $application->fresh()->status);
    }

    // ---- History cannot be destroyed

    public function test_purge_never_erases_a_version_chain(): void
    {
        $application = $this->submitted();
        $old = $this->current($application);
        $this->decide($application, 'correct');
        $this->correct($application);
        $new = $this->current($application);

        $new->delete();
        $old->delete();
        $this->travel(400)->days();

        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        foreach ([$old, $new] as $document) {
            $this->assertNotNull(Document::withTrashed()->find($document->id));
            $this->assertTrue(Storage::disk('local')->exists($document->original_file));
        }

        $this->assertSame(0, AuditLog::where('action', 'document.purged')->count());
    }

    public function test_a_file_with_a_successor_is_protected_even_if_never_stamped_superseded(): void
    {
        $application = $this->submitted();
        $old = $this->current($application);
        Storage::disk('local')->put('documents/next.pdf', 'next');
        Document::create(['request_id' => $application->id, 'title' => 'Next', 'original_file' => 'documents/next.pdf', 'file_hash' => hash('sha256', 'next'), 'version' => 2, 'replaces_document_id' => $old->id]);

        $this->assertTrue($old->fresh()->isCurrent());
        $this->assertTrue($old->fresh()->isProtectedRecord());

        $old->delete();
        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($old->id));
    }

    public function test_purge_never_erases_a_certified_record(): void
    {
        $application = $this->completed();
        $document = $this->current($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Check again']);

        // No route can do this now. The purge must hold even so.
        $document->delete();

        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($document->id));
        $this->scan($document->fresh())->assertSee('This record is no longer available.');
    }

    public function test_purge_still_removes_an_ordinary_expired_upload(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $document->delete();

        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNull(Document::withTrashed()->find($document->id));
        $this->assertFalse(Storage::disk('local')->exists($document->original_file));
    }

    public function test_a_citizen_cannot_erase_a_certified_file_or_a_corrected_version(): void
    {
        // No notary, so a correction returns the application to pending, where citizens may delete.
        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);
        $application = NotaryRequest::latest('id')->firstOrFail();
        $document = $this->current($application);

        foreach (['approve', 'complete', 'correct'] as $decision) {
            $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/{$decision}", ['reason' => 'Reviewed']);
        }

        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'No change needed']);
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertNotNull($document->fresh()->verification_code);

        $this->actingAs($this->citizen)->delete("/user/documents/{$document->id}")->assertSessionHas('error');

        $this->assertFalse($document->fresh()->trashed());
        $this->assertTrue($document->fresh()->fileExists());

        // The same, for a corrected version.
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Send the right file']);
        $this->correct($application);
        $version = $this->current($application);
        $this->assertSame('pending', $application->fresh()->status);

        $this->actingAs($this->citizen)->delete("/user/documents/{$version->id}")->assertSessionHas('error');

        $this->assertFalse($version->fresh()->trashed());
    }

    public function test_a_citizen_can_still_delete_an_ordinary_pending_upload(): void
    {
        $this->actingAs($this->citizen)->post('/user/upload', [
            'title' => 'Sale deed', 'document_type' => 'sale_deed', 'description' => 'For a house', 'file' => $this->pdf(),
        ]);
        $document = Document::latest('id')->firstOrFail();

        $this->actingAs($this->citizen)->delete("/user/documents/{$document->id}")->assertSessionHas('status');

        $this->assertTrue($document->fresh()->trashed());
        $this->assertFalse(Storage::disk('local')->exists($document->original_file));
    }

    public function test_purge_keeps_a_file_a_decision_was_made_on(): void
    {
        foreach (['reject', 'correct'] as $decision) {
            $application = $this->submitted();
            $document = $this->current($application);
            $this->decide($application, $decision);
            $document->delete();

            $this->travel(400)->days();
            $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

            $this->assertNotNull(Document::withTrashed()->find($document->id), $decision);
            $this->assertTrue(Storage::disk('local')->exists($document->original_file), $decision);
        }
    }

    public function test_a_decision_protects_the_file_after_the_status_has_moved_on(): void
    {
        foreach (['reject', 'approve'] as $decision) {
            // No notary: a note-only resubmit returns the application to pending.
            $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf($decision)]);
            $application = NotaryRequest::latest('id')->firstOrFail();
            $document = $this->current($application);

            $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/{$decision}", ['reason' => 'Reviewed']);
            $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Look again']);
            $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Nothing changed']);
            $this->assertSame('pending', $application->fresh()->status);

            $this->actingAs($this->citizen)->delete("/user/documents/{$document->id}")->assertSessionHas('error');
            $this->actingAs($this->citizen)->from('/user/requests')->post("/user/requests/{$application->id}/withdraw")->assertSessionHas('error');
            $this->actingAs($this->admin)->from('/admin/documents')->delete("/admin/documents/{$document->id}", ['reason' => 'Tidying'])->assertSessionHas('error');

            $this->assertFalse($document->fresh()->trashed());
            $this->assertFalse($application->fresh()->trashed());
            $this->assertTrue($document->fresh()->fileExists());
        }
    }

    public function test_purge_honours_a_decision_after_the_status_has_moved_on(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $this->decide($application, 'reject');
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Look again']);
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Nothing changed']);
        $this->assertSame('assigned', $application->fresh()->status);

        $this->actingAs($this->admin)->from('/admin/documents')->delete("/admin/documents/{$document->id}", ['reason' => 'Tidying'])->assertSessionHas('error');

        // Even if something removed it another way.
        $document->delete();
        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($document->id));
        $this->assertTrue(Storage::disk('local')->exists($document->original_file));
    }

    public function test_list_pages_apply_the_same_protection(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $this->decide($application, 'reject');
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Look again']);
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Nothing changed']);

        $action = 'action="'.route('admin.documents.destroy', $document).'"';
        $this->actingAs($this->admin)->get('/admin/documents')->assertOk()->assertDontSee($action, false);

        $ordinary = $this->current($this->submitted());
        $this->actingAs($this->admin)->get('/admin/documents')->assertSee('action="'.route('admin.documents.destroy', $ordinary).'"', false);
    }

    public function test_an_admin_cannot_remove_a_required_document(): void
    {
        [$application, $required] = $this->serviceApplication();

        $this->actingAs($this->admin)->from('/admin/documents')->delete("/admin/documents/{$required->id}", ['reason' => 'Tidying'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'required'));

        $this->assertFalse($required->fresh()->trashed());
    }

    public function test_a_citizen_can_withdraw_an_untouched_application_with_two_required_files(): void
    {
        [$application, $required] = $this->serviceApplication();
        $application->update(['status' => 'pending', 'notary_id' => null]);
        $other = Document::create(['request_id' => $application->id, 'title' => 'Other', 'original_file' => 'documents/other.pdf', 'requirement_key' => $required->requirement_key]);
        Storage::disk('local')->put('documents/other.pdf', 'other');

        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertSee('Withdraw Application');
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/withdraw")->assertRedirect(route('user.requests'));

        $this->assertTrue($application->fresh()->trashed());
        $this->assertFalse(Storage::disk('local')->exists($required->original_file));
        $this->assertFalse(Storage::disk('local')->exists($other->original_file));
    }

    public function test_a_decided_application_cannot_be_withdrawn_even_after_its_file_was_removed(): void
    {
        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);
        $application = NotaryRequest::latest('id')->firstOrFail();
        $document = $this->current($application);

        $this->actingAs($this->admin)->delete("/admin/documents/{$document->id}", ['reason' => 'Wrong upload']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'Nothing to review']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Send the file']);
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Will send it']);
        $this->assertSame('pending', $application->fresh()->status);

        $this->actingAs($this->citizen)->from('/user/requests')->post("/user/requests/{$application->id}/withdraw")->assertSessionHas('error');

        $this->assertFalse($application->fresh()->trashed());
        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertDontSee('Withdraw Application');
    }

    public function test_protection_survives_a_lost_audit_trail(): void
    {
        // As if every audit write failed. It never stops the action, by design. (Rows already
        // written cannot be deleted any more: the audit log is append-only, migration 000023.)
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated audit outage.'));

        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);
        $application = NotaryRequest::latest('id')->firstOrFail();
        $document = $this->current($application);

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'No']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Look again']);
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Nothing changed']);

        $this->assertSame(0, AuditLog::count(), 'No audit entry exists.');
        $this->assertSame('pending', $application->fresh()->status);

        $this->actingAs($this->citizen)->delete("/user/documents/{$document->id}")->assertSessionHas('error');
        $this->actingAs($this->citizen)->from('/user/requests')->post("/user/requests/{$application->id}/withdraw")->assertSessionHas('error');

        $this->assertTrue($document->fresh()->fileExists());
        $this->assertFalse($application->fresh()->trashed());
    }

    public function test_a_removed_certified_file_still_stops_a_withdrawal(): void
    {
        $application = $this->application('pending');
        $removed = Document::create(['request_id' => $application->id, 'title' => 'Gone', 'original_file' => 'documents/gone.pdf', 'verification_code' => 'REMOVED000000001']);
        $removed->delete();

        $this->assertFalse($application->fresh()->canBeWithdrawn());
    }

    public function test_the_list_page_keeps_protection_when_the_audit_row_is_lost(): void
    {
        // The audit row was never written (append-only, so it cannot be removed afterwards).
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated audit outage.'));
        $application = $this->submitted();
        $document = $this->current($application);
        $application->update(['status' => 'pending', 'notary_id' => null, 'decided_at' => now()]);
        $this->assertSame(0, AuditLog::count());

        $this->actingAs($this->admin)->get('/admin/documents')->assertOk()
            ->assertDontSee('action="'.route('admin.documents.destroy', $document).'"', false);
    }

    public function test_the_list_page_keeps_protection_for_a_decision_only_the_audit_log_remembers(): void
    {
        // Older applications had decided_at cleared on resubmit, so only the audit row is left.
        $application = $this->submitted();
        $document = $this->current($application);
        $application->update(['status' => 'pending', 'notary_id' => null, 'decided_at' => null]);
        AuditLog::create(['action' => 'request.rejected', 'model_type' => 'NotaryRequest', 'model_id' => $application->id, 'meta' => []]);

        $this->actingAs($this->admin)->get('/admin/documents')->assertOk()
            ->assertDontSee('action="'.route('admin.documents.destroy', $document).'"', false);
    }

    public function test_a_fault_after_commit_keeps_the_uploaded_file_of_a_saved_record(): void
    {
        AuditLog::created(function (AuditLog $entry) {
            if ($entry->action === 'document.uploaded') {
                DB::afterCommit(fn () => throw new \RuntimeException('Queue down'));
            }
        });

        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);

        $document = Document::latest('id')->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($document->original_file));
    }

    public function test_a_fault_after_commit_keeps_the_corrected_file_of_a_saved_record(): void
    {
        $application = $this->submitted();
        $this->decide($application, 'correct');

        AuditLog::created(function (AuditLog $entry) {
            if ($entry->action === 'document.superseded') {
                DB::afterCommit(fn () => throw new \RuntimeException('Queue down'));
            }
        });

        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Fixed', 'file' => $this->pdf('fixed')]);

        $this->assertTrue($this->current($application)->fileExists());
    }

    public function test_a_soft_deleted_application_still_counts_as_decided_for_the_purge(): void
    {
        $application = $this->application('rejected');
        $application->update(['decided_at' => now()]);
        Storage::disk('local')->put('documents/kept.pdf', 'kept');
        $document = Document::create(['request_id' => $application->id, 'title' => 'Kept', 'original_file' => 'documents/kept.pdf']);
        $document->delete();
        $application->delete();

        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($document->id));
    }

    public function test_withdrawing_cancels_open_appointments(): void
    {
        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);
        $byRoute = NotaryRequest::latest('id')->firstOrFail();
        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf('two')]);
        $byDelete = NotaryRequest::latest('id')->firstOrFail();

        $open = fn (NotaryRequest $application) => Appointment::create(['request_id' => $application->id, 'user_id' => $this->citizen->id, 'scheduled_at' => now()->addWeek(), 'mode' => 'video', 'status' => 'requested']);
        $first = $open($byRoute);
        $second = $open($byDelete);

        $this->actingAs($this->citizen)->post("/user/requests/{$byRoute->id}/withdraw");
        $this->actingAs($this->citizen)->delete('/user/documents/'.$this->current($byDelete)->id);

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_a_required_document_recorded_at_submission_is_needed_to_complete(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $document->update(['requirement_key' => 'present']);
        $application->update(['service_details' => ['service' => 'x', 'required_documents' => ['present', 'absent']]]);
        $this->decide($application, 'approve');

        $this->decide($application, 'complete')->assertSessionHas('error', fn ($message) => str_contains($message, 'required document is missing'));
        $this->assertSame('approved', $application->fresh()->status);

        $application->update(['service_details' => ['service' => 'x', 'required_documents' => ['present']]]);
        $this->decide($application, 'complete');
        $this->assertSame('completed', $application->fresh()->status);
    }

    public function test_the_required_list_is_the_one_recorded_not_todays_catalogue(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $application->update(['service_details' => ['service' => 'x', 'required_documents' => ['kept']]]);
        $document->update(['requirement_key' => 'kept']);

        $this->assertTrue($document->fresh()->isRequiredByService());
    }

    public function test_a_privacy_export_includes_withdrawn_applications(): void
    {
        $this->actingAs($this->citizen)->post('/user/upload', ['title' => 'Deed', 'document_type' => 'will', 'description' => 'x', 'file' => $this->pdf()]);
        $application = NotaryRequest::latest('id')->firstOrFail();
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/withdraw");

        $export = json_decode($this->actingAs($this->citizen)->get('/privacy-center/export')->assertOk()->getContent(), true);

        $this->assertContains($application->request_number, array_column($export['applications'], 'request_number'));
        $this->assertSame('removed', $export['documents'][0]['status']);
    }

    public function test_a_shared_file_survives_the_purge_of_one_record(): void
    {
        $keeper = $this->current($this->completed());
        $twin = Document::create(['request_id' => $this->application('pending')->id, 'title' => 'Twin', 'original_file' => $keeper->original_file, 'file_hash' => $keeper->file_hash]);
        $twin->delete();

        $this->travel(400)->days();
        $this->artisan('enotary:purge-expired', ['--apply' => true])->assertSuccessful();

        $this->assertNull(Document::withTrashed()->find($twin->id));
        $this->assertTrue($keeper->fresh()->fileExists());
    }

    public function test_only_the_owner_can_withdraw_and_only_before_any_decision(): void
    {
        $application = $this->submitted();
        $application->update(['status' => 'pending', 'notary_id' => null]);

        $this->actingAs($this->user('public'))->post("/user/requests/{$application->id}/withdraw")->assertForbidden();
        $this->assertFalse($application->fresh()->trashed());

        $assigned = $this->submitted();
        $this->actingAs($this->citizen)->from('/user/requests')->post("/user/requests/{$assigned->id}/withdraw")->assertSessionHas('error');
        $this->assertFalse($assigned->fresh()->trashed());
        $this->actingAs($this->citizen)->get("/user/requests/{$assigned->id}")->assertDontSee('Withdraw Application');
    }

    public function test_a_correction_can_add_a_file_without_retiring_the_one_there(): void
    {
        $application = $this->submitted();
        $existing = $this->current($application);
        $this->decide($application, 'correct');

        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertSee('Add as a new document');
        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'Added proof', 'file' => $this->pdf('proof'), 'replaces' => 'new'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($existing->fresh()->isCurrent());
        $this->assertSame(2, $application->currentDocuments()->count());
        $added = $application->currentDocuments()->whereKeyNot($existing->id)->firstOrFail();
        $this->assertSame(1, (int) $added->version);
        $this->assertNull($added->replaces_document_id);
    }

    public function test_a_nonsense_choice_of_file_is_refused(): void
    {
        $application = $this->submitted();
        $this->decide($application, 'correct');

        $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", ['note' => 'x y z', 'file' => $this->pdf('z'), 'replaces' => '1abc'])
            ->assertSessionHasErrors('replaces');
    }

    public function test_an_uncertified_file_that_changed_warns_the_reviewer(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertDontSee('Integrity check failed');

        Storage::disk('local')->put($document->original_file, 'tampered bytes');

        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertSee('Integrity check failed');
    }

    public function test_a_stored_path_the_disk_refuses_does_not_break_the_pages_that_list_it(): void
    {
        $application = $this->submitted();
        $document = $this->current($application);
        $document->forceFill(['original_file' => '../../outside.pdf'])->save();

        $this->actingAs($this->citizen)->get('/user/documents')->assertOk();
        $this->actingAs($this->citizen)->get("/user/documents/{$document->id}")->assertOk();
        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertOk();
        $this->actingAs($this->admin)->get('/admin/documents')->assertOk();
        $this->actingAs($this->admin)->get("/admin/documents/{$document->id}")->assertOk();
    }

    public function test_a_blocked_page_records_the_refusal_once_not_on_every_reload(): void
    {
        $application = $this->completed();
        Storage::disk('local')->put($this->current($application)->original_file, 'tampered bytes');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertNotFound();
        }

        $this->assertSame(1, AuditLog::where('action', 'certificate.blocked')->count());
    }

    public function test_a_refused_record_does_not_publish_its_fingerprint(): void
    {
        $application = $this->completed();
        $document = $this->current($application);
        $this->scan($document)->assertSee($document->file_hash);

        Storage::disk('local')->delete($document->original_file);

        $this->scan($document)->assertDontSee($document->file_hash);
    }

    public function test_each_protection_holds_alone_for_a_record_with_no_audit_trail(): void
    {
        // Imported or legacy rows may have no audit entries, so no rule may lean on the others.
        $application = $this->application('pending');
        $older = Document::create(['request_id' => $application->id, 'title' => 'Older', 'original_file' => 'documents/older.pdf']);

        $ordinary = Document::create(['request_id' => $application->id, 'title' => 'Plain', 'original_file' => 'documents/plain.pdf']);
        $this->assertFalse($ordinary->isProtectedRecord());

        $certified = Document::create(['request_id' => $application->id, 'title' => 'C', 'original_file' => 'documents/c.pdf', 'verification_code' => 'NOAUDIT000000001']);
        $corrected = Document::create(['request_id' => $application->id, 'title' => 'V', 'original_file' => 'documents/v.pdf', 'replaces_document_id' => $older->id]);
        $superseded = Document::create(['request_id' => $application->id, 'title' => 'S', 'original_file' => 'documents/s.pdf', 'superseded_at' => now()]);

        $movedOn = Document::create(['request_id' => tap($this->application('pending'), fn ($a) => $a->update(['decided_at' => now()]))->id, 'title' => 'M', 'original_file' => 'documents/m.pdf']);
        $this->assertTrue($movedOn->isProtectedRecord());

        $this->assertTrue($certified->isProtectedRecord());
        $this->assertTrue($corrected->isProtectedRecord());
        $this->assertTrue($superseded->isProtectedRecord());

        $rejected = Document::create(['request_id' => $this->application('rejected')->id, 'title' => 'R', 'original_file' => 'documents/r.pdf']);
        $this->assertTrue($rejected->isProtectedRecord());
        $this->assertSame(0, AuditLog::where('action', 'like', 'request.%')->count());
    }

    public function test_an_admin_cannot_remove_anything_on_the_record(): void
    {
        $rejected = $this->submitted();
        $this->decide($rejected, 'reject');

        $corrected = $this->submitted();
        $this->decide($corrected, 'correct');
        $this->correct($corrected);

        $certified = $this->completed();
        $code = $this->current($certified);
        $this->actingAs($this->admin)->post("/admin/requests/{$certified->id}/reject", ['reason' => 'Issued in error']);

        foreach ([$this->current($rejected), $this->current($corrected), $code] as $document) {
            $this->actingAs($this->admin)->from('/admin/documents')->delete("/admin/documents/{$document->id}", ['reason' => 'Tidying up'])
                ->assertSessionHas('error');

            $this->assertFalse($document->fresh()->trashed());
            $this->actingAs($this->admin)->get("/admin/documents/{$document->id}")->assertDontSee('action="'.route('admin.documents.destroy', $document).'"', false);
        }
    }

    public function test_an_admin_can_still_remove_an_ordinary_file_under_review(): void
    {
        $document = $this->current($this->submitted());

        $this->actingAs($this->admin)->delete("/admin/documents/{$document->id}", ['reason' => 'Wrong upload'])->assertSessionHas('status');

        $this->assertTrue($document->fresh()->trashed());
    }

    public function test_a_file_shared_by_two_records_survives_deleting_one(): void
    {
        $document = $this->current($this->completed());
        $twin = Document::create(['request_id' => $this->submitted()->id, 'title' => 'Twin', 'original_file' => $document->original_file, 'file_hash' => $document->file_hash]);

        $twin->deleteFile();

        $this->assertTrue($document->fresh()->fileExists());
    }

    /** An application for a service with a required document, holding that document. */
    private function serviceApplication(): array
    {
        $service = app(ServiceCatalogue::class)->all()->first(fn ($s) => collect($s['documents'])->contains('required', true));
        $application = $this->submitted();
        $application->update(['service_slug' => $service['slug']]);
        $required = $this->current($application);
        $required->update(['requirement_key' => collect($service['documents'])->firstWhere('required', true)['key']]);

        return [$application->fresh(), $required->fresh()];
    }

    public function test_a_required_document_cannot_be_deleted_while_others_remain(): void
    {
        [$application, $required] = $this->serviceApplication();
        $application->update(['status' => 'pending', 'notary_id' => null]);
        $other = Document::create(['request_id' => $application->id, 'title' => 'Extra', 'original_file' => 'documents/extra.pdf']);

        $this->actingAs($this->citizen)->delete("/user/documents/{$required->id}")->assertSessionHas('error', fn ($message) => str_contains($message, 'required'));
        $this->assertFalse($required->fresh()->trashed());

        $this->actingAs($this->citizen)->delete("/user/documents/{$other->id}")->assertSessionHas('status');
    }

    public function test_the_remove_button_is_not_offered_for_history(): void
    {
        $application = $this->submitted();
        $old = $this->current($application);
        $this->decide($application, 'correct');
        $this->correct($application);

        // The form's action, as the same address also appears inside the page's other links.
        $removal = fn (string $route) => 'action="'.route($route, $old).'"';

        $this->actingAs($this->admin)->get("/admin/documents/{$old->id}")->assertOk()
            ->assertSee('Superseded')->assertDontSee($removal('admin.documents.destroy'), false);

        $this->actingAs($this->citizen)->get('/user/documents')->assertOk()
            ->assertSee('Superseded')->assertDontSee($removal('user.documents.destroy'), false);
    }

    // ---- What the public check shows

    public function test_a_revoked_record_shows_no_approving_status(): void
    {
        $application = $this->completed();
        $document = $this->current($application);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Check again']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Still checking']);

        $this->scan($document)->assertSee('This record has been revoked.')->assertDontSee('en-badge-approved', false);
    }

    public function test_a_code_typed_in_lower_case_is_the_same_record(): void
    {
        $document = $this->current($this->completed());
        Auth::logout();

        $this->get('/verify/'.strtolower($document->verification_code))->assertOk()->assertSee('This record exists and is current.');
    }

    public function test_reissuing_keeps_the_date_printed_on_the_certificate(): void
    {
        $application = $this->completed();
        $issued = $this->current($application)->certified_at;

        $this->travel(10)->days();
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Check again']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Fine after all']);
        $this->decide($application, 'complete');

        $this->assertTrue($issued->equalTo($this->current($application)->certified_at));
    }

    public function test_the_privacy_export_tells_versions_apart(): void
    {
        $application = $this->submitted();
        $this->decide($application, 'correct');
        $this->correct($application);

        $export = json_decode($this->actingAs($this->citizen)->get('/privacy-center/export')->assertOk()->getContent(), true);

        $this->assertEqualsCanonicalizing([1, 2], array_column($export['documents'], 'version'));
        $this->assertCount(1, array_filter(array_column($export['documents'], 'superseded_at')));
        $this->assertEqualsCanonicalizing(['superseded', 'current'], array_column($export['documents'], 'status'));
    }

    public function test_the_privacy_export_agrees_with_the_public_check(): void
    {
        $application = $this->completed();
        $document = $this->current($application);
        $status = fn () => json_decode($this->actingAs($this->citizen)->get('/privacy-center/export')->assertOk()->getContent(), true)['documents'][0]['status'];

        $this->assertSame('certified', $status());

        Storage::disk('local')->put($document->original_file, 'tampered bytes');
        $this->assertSame('integrity_failed', $status());
    }

    public function test_receipt_and_review_list_only_the_current_version(): void
    {
        $application = $this->submitted();
        $this->current($application)->update(['title' => 'OLDVERSIONTITLE']);
        $this->decide($application, 'correct');
        $this->correct($application);
        $this->current($application)->update(['title' => 'NEWVERSIONTITLE']);

        foreach (['receipt', 'review-report', 'summary'] as $name) {
            $this->actingAs($this->citizen)->get("/applications/{$application->id}/{$name}.pdf")->assertOk();
            $this->assertStringContainsString('NEWVERSIONTITLE', $this->lastPdf, $name);
            $this->assertStringNotContainsString('OLDVERSIONTITLE', $this->lastPdf, $name);
        }
    }

    // ---- Status shown in history

    public function test_history_labels_each_outcome_on_the_pages_people_use(): void
    {
        $application = $this->completed();
        $old = $this->current($application);
        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertSee('Certified');

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Check again']);
        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertSee('Revoked');

        $this->correct($application);
        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertSee('Superseded')->assertSee('Version 2');

        Storage::disk('local')->put($old->original_file, 'tampered old version');
        $this->assertSame('superseded', $old->fresh()->lifecycleStatus(), 'a replaced file stays replaced');
    }
}
