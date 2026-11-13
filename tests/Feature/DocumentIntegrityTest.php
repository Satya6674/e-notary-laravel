<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BR-008. A certificate must only ever vouch for the current version of a file, and
 * the public check must look at that file, not just at its application.
 *
 * Evidence level: real controllers, database and file bytes on a faked disk.
 * Notifications are faked. scripts/Test-MariaDB.ps1 runs this class on MariaDB too.
 */
class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $citizen;

    private User $notary;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Storage::fake('local');
        Notification::fake();

        $this->citizen = $this->user('public');
        $this->notary = $this->user('notary');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        static $i = 100;
        $i++;

        return User::create([
            'name' => "Person {$i}", 'email' => "d{$i}@example.test", 'mobile' => '9400000'.$i,
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function application(array $extra = [], ?User $owner = null): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => ($owner ?? $this->citizen)->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'assigned', 'notary_id' => $this->notary->id,
        ], $extra));
    }

    private function document(NotaryRequest $application, string $title = 'Sale deed', array $extra = []): Document
    {
        $path = 'documents/'.uniqid('f', true).'.pdf';
        $content = "%PDF-1.4 {$title} ".$path;
        Storage::disk('local')->put($path, $content);

        return Document::create(array_merge([
            'request_id' => $application->id, 'title' => $title, 'original_file' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($content), 'file_hash' => hash('sha256', $content),
        ], $extra));
    }

    private function pdf(string $text = 'fixed'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fixed.pdf', "%PDF-1.4\n{$text}\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    private function resubmit(NotaryRequest $application, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->citizen)->post("/user/requests/{$application->id}/resubmit", $data + ['note' => 'Fixed it']);
    }

    /** approved -> completed by the assigned notary. */
    private function complete(NotaryRequest $application): void
    {
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/complete", ['reason' => 'Review finished']);
    }

    /** A completed application that an admin reopened, so the citizen can correct it. */
    private function completedThenReopened(string ...$titles): array
    {
        $application = $this->application(['status' => 'approved']);
        $documents = array_map(fn ($title) => $this->document($application, $title), $titles ?: ['Sale deed']);

        $this->complete($application);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/correct", ['reason' => 'Wrong file certified']);

        return [$application->fresh(), array_map(fn ($document) => $document->fresh(), $documents)];
    }

    private function approveAndComplete(NotaryRequest $application): void
    {
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Looks right']);
        $this->complete($application);
    }

    // ---- Correction and version history

    public function test_a_correction_replaces_the_file_and_keeps_the_old_one_as_history(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $old = $this->document($application, 'Sale deed', ['requirement_key' => 'deed']);

        $this->resubmit($application, ['file' => $this->pdf()])->assertRedirect();

        $new = $old->fresh()->nextVersion;

        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertFalse($old->fresh()->isCurrent());
        $this->assertTrue($new->isCurrent());
        $this->assertSame(2, (int) $new->version);
        $this->assertSame($old->id, (int) $new->replaces_document_id);
        $this->assertSame('Sale deed', $new->title);
        $this->assertSame('deed', $new->requirement_key);
        $this->assertTrue($old->fresh()->fileExists(), 'history must keep the old file');
        $this->assertSame([$new->id], $application->currentDocuments()->pluck('id')->all());
    }

    public function test_replacing_is_audited_with_ids_only(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $old = $this->document($application);

        $this->resubmit($application, ['file' => $this->pdf()]);

        $entry = AuditLog::where('action', 'document.superseded')->firstOrFail();

        $this->assertSame($old->id, (int) $entry->model_id);
        $this->assertSame($old->fresh()->nextVersion->id, $entry->meta['replaced_by']);
        $this->assertArrayNotHasKey('original_file', $entry->meta);
    }

    public function test_a_note_with_no_file_retires_nothing(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $document = $this->document($application);

        $this->resubmit($application)->assertRedirect();

        $this->assertTrue($document->fresh()->isCurrent());
        $this->assertSame(1, $application->documents()->count());
    }

    public function test_with_several_files_the_citizen_must_say_which_one_is_replaced(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $deed = $this->document($application, 'Sale deed');
        $id = $this->document($application, 'Identity proof');

        $this->resubmit($application, ['file' => $this->pdf()])->assertSessionHasErrors('replaces');

        $this->assertSame('correction_requested', $application->fresh()->status);
        $this->assertSame(2, $application->currentDocuments()->count());

        $this->resubmit($application, ['file' => $this->pdf(), 'replaces' => $id->id])->assertSessionHasNoErrors();

        $this->assertTrue($deed->fresh()->isCurrent());
        $this->assertFalse($id->fresh()->isCurrent());
    }

    public function test_a_file_outside_this_application_or_already_replaced_cannot_be_named(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $this->document($application);
        $elsewhere = $this->document($this->application(['status' => 'correction_requested'], $this->user('public')));
        $old = $this->document($application, 'Older', ['superseded_at' => now()]);

        foreach ([$elsewhere, $old] as $wrong) {
            $this->resubmit($application, ['file' => $this->pdf(), 'replaces' => $wrong->id])->assertSessionHasErrors('replaces');
        }

        $this->assertTrue($elsewhere->fresh()->isCurrent());
    }

    public function test_only_the_owner_can_replace_a_file_and_only_while_a_correction_is_open(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $document = $this->document($application);

        $this->actingAs($this->user('public'))->post("/user/requests/{$application->id}/resubmit", ['note' => 'Mine now', 'file' => $this->pdf()])->assertForbidden();

        $open = $this->application(['status' => 'assigned']);
        $this->resubmit($open, ['file' => $this->pdf()])->assertForbidden();

        $this->assertTrue($document->fresh()->isCurrent());
    }

    public function test_the_database_refuses_two_successors_for_one_version(): void
    {
        $application = $this->application();
        $old = $this->document($application);
        $this->document($application, 'First', ['replaces_document_id' => $old->id, 'version' => 2]);

        $this->expectException(QueryException::class);

        $this->document($application, 'Second', ['replaces_document_id' => $old->id, 'version' => 2]);
    }

    public function test_history_stays_readable_by_those_allowed_but_cannot_be_deleted(): void
    {
        $application = $this->application(['status' => 'pending', 'notary_id' => null]);
        $current = $this->document($application);
        $old = $this->document($application, 'Earlier', ['superseded_at' => now()]);

        $this->actingAs($this->citizen)->get("/user/documents/{$old->id}/download")->assertOk();
        $this->actingAs($this->user('public'))->get("/user/documents/{$old->id}/download")->assertForbidden();

        $this->actingAs($this->citizen)->delete("/user/documents/{$old->id}")->assertSessionHas('error');
        $this->assertFalse($old->fresh()->trashed());
        $this->assertTrue($old->fresh()->fileExists());
        $this->assertNotNull($current->fresh());
    }

    public function test_deleting_the_last_current_file_cannot_withdraw_an_application_with_history(): void
    {
        $application = $this->application(['status' => 'pending', 'notary_id' => null]);
        $current = $this->document($application);
        $this->document($application, 'Earlier', ['superseded_at' => now()]);

        $this->actingAs($this->citizen)->delete("/user/documents/{$current->id}")->assertSessionHas('error');

        $this->assertFalse($application->fresh()->trashed());
        $this->assertTrue($current->fresh()->fileExists());
    }

    public function test_pages_label_a_replaced_file(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $this->document($application);
        $this->resubmit($application, ['file' => $this->pdf()]);

        $this->actingAs($this->notary)->get("/notary/request/{$application->id}")->assertOk()->assertSee('Superseded')->assertSee('Version 2');
        $this->actingAs($this->citizen)->get("/user/requests/{$application->id}")->assertOk()->assertSee('Superseded');
    }

    // ---- Certificates follow the current version

    public function test_completion_certifies_only_current_versions(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $old = $this->document($application, 'Sale deed');
        $this->resubmit($application, ['file' => $this->pdf()]);

        $this->approveAndComplete($application);

        $this->assertSame('completed', $application->fresh()->status);
        $this->assertNull($old->fresh()->verification_code);
        $this->assertNull($old->fresh()->certified_at);
        $this->assertNotNull($old->fresh()->nextVersion->verification_code);
    }

    public function test_a_replaced_certificate_stops_being_current_and_the_new_one_starts(): void
    {
        [$application, [$old]] = $this->completedThenReopened();
        $oldCode = $old->verification_code;
        $this->assertNotNull($oldCode);

        $this->resubmit($application, ['file' => $this->pdf()]);
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Right file now']);
        $this->complete($application);

        $new = $old->fresh()->nextVersion;

        $this->assertNotSame($oldCode, $new->verification_code);
        $this->get("/verify/{$oldCode}")->assertOk()
            ->assertSee('This record was replaced by a newer version and is no longer current.')
            ->assertDontSee('This record exists and is current.');
        $this->get("/verify/{$new->verification_code}")->assertOk()->assertSee('This record exists and is current.');
    }

    public function test_the_certificate_page_lists_only_the_current_version(): void
    {
        [$application, [$old]] = $this->completedThenReopened();
        $oldCode = $old->verification_code;

        $this->resubmit($application, ['file' => $this->pdf()]);
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Right file now']);
        $this->complete($application);

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertOk()
            ->assertSee($old->fresh()->nextVersion->verification_code)->assertDontSee($oldCode);
    }

    public function test_a_file_not_replaced_keeps_its_code_when_another_is_corrected(): void
    {
        [$application, [$deed, $id]] = $this->completedThenReopened('Sale deed', 'Identity proof');
        $idCode = $id->verification_code;

        $this->resubmit($application, ['file' => $this->pdf(), 'replaces' => $deed->id]);
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Right file now']);
        $this->complete($application);

        $this->assertSame($idCode, $id->fresh()->verification_code);
        $this->get("/verify/{$idCode}")->assertSee('This record exists and is current.');
        $this->get("/verify/{$deed->verification_code}")->assertSee('This record was replaced by a newer version');
    }

    public function test_a_replaced_record_shows_no_status_or_personal_details(): void
    {
        [$application, [$old]] = $this->completedThenReopened();
        $this->resubmit($application, ['file' => $this->pdf()]);

        // A signed-out visitor, as the public check is. Signed in, the page header names the user.
        Auth::logout();

        $this->get("/verify/{$old->verification_code}")->assertOk()
            ->assertSee('Version')->assertDontSee('en-badge-', false)->assertDontSee($this->citizen->name)->assertDontSee($this->citizen->email);
    }

    // ---- Rejection and revocation

    public function test_rejecting_a_completed_application_revokes_its_certificate(): void
    {
        $application = $this->application(['status' => 'approved']);
        $document = $this->document($application);
        $this->complete($application);
        $code = $document->fresh()->verification_code;

        $this->get("/verify/{$code}")->assertSee('This record exists and is current.');

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/reject", ['reason' => 'Issued in error']);

        $this->get("/verify/{$code}")->assertSee('This record has been revoked.');
    }

    public function test_reopening_an_application_revokes_until_it_is_completed_again(): void
    {
        [$application, [$document]] = $this->completedThenReopened();

        $this->get("/verify/{$document->verification_code}")->assertSee('This record has been revoked.');

        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/approve", ['reason' => 'Fine after all']);
        $this->complete($application);

        $this->get("/verify/{$document->verification_code}")->assertSee('This record exists and is current.');
    }

    public function test_a_rejected_application_is_never_certified(): void
    {
        $application = $this->application();
        $document = $this->document($application);

        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/reject", ['reason' => 'Not acceptable']);
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/complete", ['reason' => 'Trying anyway']);
        $this->actingAs($this->admin)->post("/admin/requests/{$application->id}/complete", ['reason' => 'Trying anyway']);

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertNull($document->fresh()->verification_code);
    }

    // ---- The check looks at the file itself

    public function test_an_altered_file_cannot_be_confirmed(): void
    {
        $application = $this->application(['status' => 'approved']);
        $document = $this->document($application);
        $this->complete($application);
        $code = $document->fresh()->verification_code;

        Storage::disk('local')->put($document->original_file, 'tampered bytes');

        $this->get("/verify/{$code}")->assertOk()
            ->assertSee('This record cannot be confirmed')->assertDontSee('This record exists and is current.');
    }

    public function test_a_missing_file_cannot_be_confirmed(): void
    {
        $application = $this->application(['status' => 'approved']);
        $document = $this->document($application);
        $this->complete($application);

        Storage::disk('local')->delete($document->original_file);

        $this->get('/verify/'.$document->fresh()->verification_code)->assertSee('This record cannot be confirmed');
    }

    public function test_a_record_with_no_fingerprint_cannot_be_confirmed(): void
    {
        $application = $this->application(['status' => 'completed']);
        $document = $this->document($application, 'Old upload', [
            'file_hash' => null, 'verification_code' => 'LEGACY0000000001', 'certified_at' => now(),
        ]);

        $this->get("/verify/{$document->verification_code}")->assertSee('This record cannot be confirmed');
    }

    public function test_a_certificate_is_withheld_when_a_current_file_has_changed(): void
    {
        $application = $this->application(['status' => 'approved']);
        $document = $this->document($application);
        Storage::disk('local')->put($document->original_file, 'tampered before completion');

        $this->actingAs($this->notary)->from("/notary/request/{$application->id}")
            ->post("/notary/request/{$application->id}/complete", ['reason' => 'Review finished'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'integrity check'));

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNull($document->fresh()->verification_code);
        $this->assertSame(1, AuditLog::where('action', 'certificate.withheld')->count());
    }

    public function test_a_broken_old_version_does_not_block_completing_the_current_one(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $old = $this->document($application);
        $this->resubmit($application, ['file' => $this->pdf()]);
        Storage::disk('local')->delete($old->original_file);

        $this->approveAndComplete($application);

        $this->assertSame('completed', $application->fresh()->status);
    }

    // ---- Found by independent testing

    public function test_the_certificate_page_and_pdf_are_blocked_for_a_record_the_public_check_refuses(): void
    {
        $application = $this->application(['status' => 'approved']);
        $document = $this->document($application);
        $this->complete($application);
        $code = $document->fresh()->verification_code;

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertOk()->assertSee($code);

        Storage::disk('local')->put($document->original_file, 'tampered after completion');

        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate")->assertNotFound()->assertDontSee($code);
        $this->actingAs($this->citizen)->get("/applications/{$application->id}/certificate.pdf")->assertNotFound();

        $this->assertSame(0, $application->fresh()->load('documents')->validCertificates()->count());
    }

    public function test_an_application_with_no_current_document_cannot_complete(): void
    {
        $application = $this->application(['status' => 'approved']);

        $this->actingAs($this->notary)->from("/notary/request/{$application->id}")
            ->post("/notary/request/{$application->id}/complete", ['reason' => 'Review finished'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'no current document'));

        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_an_admin_cannot_remove_a_replaced_version(): void
    {
        $application = $this->application(['status' => 'pending', 'notary_id' => null]);
        $old = $this->document($application, 'Earlier', ['superseded_at' => now()]);

        $this->actingAs($this->admin)->from('/admin/documents')->delete("/admin/documents/{$old->id}", ['reason' => 'Tidying up'])
            ->assertSessionHas('error');

        $this->assertFalse($old->fresh()->trashed());
    }

    public function test_removing_the_newest_version_cannot_lead_to_an_empty_completion(): void
    {
        $application = $this->application(['status' => 'correction_requested']);
        $old = $this->document($application);
        $this->resubmit($application, ['file' => $this->pdf()]);
        $new = $old->fresh()->nextVersion;

        // No route can do this now. The completion gate must hold even so.
        $new->delete();
        $this->actingAs($this->notary)->post("/notary/request/{$application->id}/approve", ['reason' => 'Looks right']);
        $this->complete($application);

        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_a_stored_path_the_disk_refuses_reads_as_unconfirmed_not_a_server_error(): void
    {
        $application = $this->application(['status' => 'completed']);

        foreach (['../../outside.pdf', 'documents'] as $i => $path) {
            $document = $this->document($application, 'Odd');
            $document->forceFill(['original_file' => $path, 'verification_code' => 'ODDPATH00000000'.$i, 'certified_at' => now()])->save();

            $this->get("/verify/{$document->verification_code}")->assertOk()->assertSee('This record cannot be confirmed');
        }
    }

    public function test_an_array_code_does_not_break_the_public_check(): void
    {
        $this->get('/verify?code[]=x')->assertOk();
    }
}
