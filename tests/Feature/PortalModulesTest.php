<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Support\Platform;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function user(string $role = 'public'): User
    {
        static $i = 400;
        $i++;

        return User::create([
            'name' => "Person {$i}", 'email' => "m{$i}@example.test", 'mobile' => '9200000'.$i,
            'password' => Hash::make('password'), 'role' => $role, 'email_verified_at' => now(),
            'mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function application(User $owner, array $extra = []): NotaryRequest
    {
        return NotaryRequest::create(array_merge([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ], $extra));
    }

    /** A real PNG built by hand, so these tests do not need the GD extension. */
    private function png(string $name, int $size = 120): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $pixels = str_repeat("\0".str_repeat("\xC9\x25\x2B", $size), $size);

        return UploadedFile::fake()->createWithContent($name, "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($pixels))
            .$chunk('IEND', ''));
    }

    // ---- Documents

    public function test_docx_upload_stores_the_server_detected_type_and_a_hash(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        Notification::fake();

        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip->open($path, \ZipArchive::OVERWRITE);
        // Entry order matters: file type detection reads the first entries, as Word writes them.
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
        $zip->close();

        $this->actingAs($this->user())->post('/user/upload', [
            'title' => 'Word file', 'document_type' => 'will', 'description' => 'x',
            'file' => new UploadedFile($path, 'sample.docx', null, null, true),
        ])->assertRedirect(route('user.documents'));

        $document = Document::firstOrFail();
        $this->assertSame(hash_file('sha256', $path), $document->file_hash);
        $this->assertNotEmpty($document->mime_type);

        @unlink($path);
    }

    public function test_preview_is_inline_for_the_allowed_and_refused_for_others(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $owner = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($owner, ['status' => 'assigned', 'notary_id' => $notary->id]);

        Storage::disk(Document::PRIVATE_DISK)->put('documents/a.pdf', "%PDF-1.4\n%%EOF\n");
        $document = Document::create(['request_id' => $application->id, 'title' => 'A', 'original_file' => 'documents/a.pdf']);

        $response = $this->actingAs($owner)->get("/documents/{$document->id}/preview")->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('content-security-policy'));

        $this->actingAs($notary)->get("/documents/{$document->id}/preview")->assertOk();
        $this->actingAs($this->user('admin'))->get("/documents/{$document->id}/preview")->assertOk();
        $this->actingAs($this->user())->get("/documents/{$document->id}/preview")->assertForbidden();
        $this->actingAs($this->user('notary'))->get("/documents/{$document->id}/preview")->assertForbidden();
    }

    public function test_html_disguised_upload_cannot_be_previewed(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $owner = $this->user();

        Storage::disk(Document::PRIVATE_DISK)->put('documents/x.pdf', '<html><script>alert(1)</script></html>');
        $document = Document::create(['request_id' => $this->application($owner)->id, 'title' => 'X', 'original_file' => 'documents/x.pdf']);

        $this->actingAs($owner)->get("/documents/{$document->id}/preview")->assertNotFound();
    }

    public function test_deleting_the_only_document_withdraws_the_empty_application(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $owner = $this->user();
        $application = $this->application($owner);

        Storage::disk(Document::PRIVATE_DISK)->put('documents/a.pdf', 'x');
        $document = Document::create(['request_id' => $application->id, 'title' => 'A', 'original_file' => 'documents/a.pdf']);

        $this->actingAs($owner)->delete("/user/documents/{$document->id}")->assertRedirect();

        $this->assertSoftDeleted('notary_requests', ['id' => $application->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.withdrawn', 'model_id' => $application->id]);
    }

    // ---- Verification

    public function test_public_lookup_reads_the_database_and_shows_no_personal_details(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['status' => 'completed']);
        Document::create([
            'request_id' => $application->id, 'title' => 'Private title', 'original_file' => 'documents/a.pdf',
            'document_number' => 'DEMO-2026-ABCDEFGH', 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now(),
        ]);

        $this->get('/verify/ABCDEFGHJKLMNPQR')->assertOk()
            ->assertSee('DEMO-2026-ABCDEFGH')->assertSee('This record has no legal effect.')
            ->assertDontSee($owner->name)->assertDontSee($owner->email)->assertDontSee('Private title');

        $this->get('/verify/ZZZZZZZZZZZZZZZZ')->assertOk()->assertSee('No record matches this code');
        $this->get("/verify/' OR 1=1")->assertOk()->assertSee('No record matches this code');
        $this->get('/verify?code=abcdefghjklmnpqr')->assertRedirect('/verify/ABCDEFGHJKLMNPQR');
    }

    public function test_certificate_exists_only_for_completed_applications_and_says_it_is_a_demo(): void
    {
        $owner = $this->user();
        $application = $this->application($owner);

        $this->actingAs($owner)->get("/applications/{$application->id}/certificate")->assertNotFound();

        $application->update(['status' => 'completed']);
        Storage::fake(Document::PRIVATE_DISK);
        Storage::disk(Document::PRIVATE_DISK)->put('documents/x.pdf', 'content');
        Document::create(['request_id' => $application->id, 'title' => 'T', 'original_file' => 'documents/x.pdf', 'file_hash' => hash('sha256', 'content'), 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);

        $this->actingAs($owner)->get("/applications/{$application->id}/certificate")->assertOk()
            ->assertSee('NOT LEGALLY VALID')->assertSee(route('verify.show', 'ABCDEFGHJKLMNPQR'), false);
    }

    // ---- Appointments

    public function test_citizen_books_notary_confirms_and_both_are_notified_in_the_app(): void
    {
        $citizen = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($citizen, ['status' => 'assigned', 'notary_id' => $notary->id]);
        $when = now()->addDays(2)->format('Y-m-d H:i');

        $this->actingAs($citizen)->post('/appointments', ['request_id' => $application->id, 'scheduled_at' => $when, 'mode' => 'video'])
            ->assertSessionHas('status');

        $appointment = Appointment::firstOrFail();
        $this->assertSame($notary->id, $appointment->notary_id);
        $this->assertSame(1, $notary->unreadNotifications()->count());

        // Only one open appointment per application.
        $this->actingAs($citizen)->post('/appointments', ['request_id' => $application->id, 'scheduled_at' => $when, 'mode' => 'video'])
            ->assertSessionHas('error');

        // A citizen may cancel but not confirm; another notary may do neither.
        $this->actingAs($citizen)->patch("/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($this->user('notary'))->patch("/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertForbidden();

        $this->actingAs($notary)->patch("/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertSessionHas('status');
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertSame(1, $citizen->unreadNotifications()->count());
    }

    public function test_appointment_rules(): void
    {
        $citizen = $this->user();
        $mine = $this->application($citizen);
        $theirs = $this->application($this->user());
        $when = now()->addDay()->format('Y-m-d H:i');

        $this->actingAs($citizen)->post('/appointments', ['request_id' => $theirs->id, 'scheduled_at' => $when, 'mode' => 'video'])->assertSessionHasErrors('request_id');
        $this->actingAs($citizen)->post('/appointments', ['request_id' => $mine->id, 'scheduled_at' => now()->subDay()->format('Y-m-d H:i'), 'mode' => 'video'])->assertSessionHasErrors('scheduled_at');

        Setting::put('appointments_enabled', '0');
        $this->actingAs($citizen)->post('/appointments', ['request_id' => $mine->id, 'scheduled_at' => $when, 'mode' => 'video'])->assertForbidden();
    }

    public function test_lists_show_only_what_each_role_may_see(): void
    {
        $citizen = $this->user();
        $other = $this->user();
        $mine = $this->application($citizen);
        Appointment::create(['request_id' => $mine->id, 'user_id' => $citizen->id, 'scheduled_at' => now()->addDay()]);

        $this->actingAs($citizen)->get('/appointments')->assertOk()->assertSee($mine->request_number);
        $this->actingAs($other)->get('/appointments')->assertOk()->assertDontSee($mine->request_number);
        $this->actingAs($this->user('admin'))->get('/appointments')->assertOk()->assertSee($mine->request_number);
    }

    // ---- Support and notifications

    public function test_support_ticket_conversation_and_privacy(): void
    {
        $citizen = $this->user();
        $admin = $this->user('admin');

        $this->actingAs($citizen)->post('/support', ['subject' => 'Help please', 'category' => 'account', 'body' => 'I need help'])->assertRedirect();
        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($this->user())->get("/support/{$ticket->id}")->assertForbidden();
        $this->actingAs($this->user('notary'))->post("/support/{$ticket->id}/reply", ['body' => 'Hi'])->assertForbidden();

        $this->actingAs($admin)->post("/support/{$ticket->id}/reply", ['body' => 'Here is the answer'])->assertSessionHas('status');
        $this->assertSame('answered', $ticket->fresh()->status);
        $this->assertSame(1, $citizen->unreadNotifications()->count());

        $this->actingAs($citizen)->post("/support/{$ticket->id}/close")->assertRedirect();
        $this->actingAs($citizen)->post("/support/{$ticket->id}/reply", ['body' => 'One more'])->assertSessionHas('error');
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_notification_can_be_opened_only_by_its_owner(): void
    {
        $owner = $this->user();
        $owner->notifyNow(new PortalNotification('Title', 'Body', route('support.index')), ['database']);
        $id = $owner->notifications()->firstOrFail()->id;

        $this->actingAs($this->user())->get("/notifications/{$id}")->assertNotFound();
        $this->actingAs($owner)->get("/notifications/{$id}")->assertRedirect(route('support.index'));
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    // ---- Profile photo

    public function test_photo_is_private_and_replaced_cleanly(): void
    {
        Storage::fake(Document::PRIVATE_DISK);
        $citizen = $this->user();

        $this->actingAs($citizen)->post('/profile/photo', ['photo' => $this->png('a.png')])->assertSessionHas('status');
        $first = $citizen->fresh()->photo_path;
        Storage::disk(Document::PRIVATE_DISK)->assertExists($first);

        $this->actingAs($citizen)->post('/profile/photo', ['photo' => $this->png('b.png')]);
        Storage::disk(Document::PRIVATE_DISK)->assertMissing($first);

        $this->actingAs($citizen)->get("/profile/photo/{$citizen->id}")->assertOk();
        $this->actingAs($this->user('admin'))->get("/profile/photo/{$citizen->id}")->assertOk();
        $this->actingAs($this->user())->get("/profile/photo/{$citizen->id}")->assertNotFound();
        $this->actingAs($this->user('notary'))->get("/profile/photo/{$citizen->id}")->assertNotFound();

        $this->actingAs($citizen)->post('/profile/photo', ['photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    }

    // ---- Reports

    public function test_csv_and_pdf_export_with_scope_and_formula_protection(): void
    {
        $notary = $this->user('notary');
        $citizen = $this->user();
        $citizen->update(['name' => '=HYPERLINK("x")']);
        $mine = $this->application($citizen, ['status' => 'assigned', 'notary_id' => $notary->id]);
        $other = $this->application($citizen);

        $csv = $this->actingAs($notary)->get('/reports/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($mine->request_number, $csv);
        $this->assertStringNotContainsString($other->request_number, $csv);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);

        $pdf = $this->actingAs($this->user('admin'))->get('/reports/export.pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->actingAs($citizen)->get('/reports')->assertForbidden();
        $this->actingAs($citizen)->get('/reports/export.csv')->assertForbidden();
    }

    // ---- Settings and language

    public function test_only_admin_can_change_site_settings_and_the_notice_appears(): void
    {
        $citizen = $this->user();
        $payload = ['portal_notice' => 'Maintenance tonight', 'default_locale' => 'en'];

        $this->actingAs($citizen)->put('/admin/settings', $payload)->assertForbidden();

        $this->actingAs($this->user('admin'))->put('/admin/settings', $payload)->assertSessionHas('status');
        $this->actingAs($citizen)->get('/dashboard')->assertSee('Maintenance tonight');
    }

    public function test_language_switch_is_remembered_and_rejects_unknown_codes(): void
    {
        $citizen = $this->user();

        $this->actingAs($citizen)->post('/locale', ['locale' => 'hi'])->assertRedirect();
        $this->assertSame('hi', $citizen->fresh()->locale);

        $this->get('/dashboard')->assertOk();
        $this->assertSame('hi', app()->getLocale());

        $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }

    public function test_every_translation_key_used_in_views_exists_in_hindi_and_punjabi(): void
    {
        $keys = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                $keys[stripslashes($key)] = true;
            }
        }

        foreach (['hi', 'pa'] as $locale) {
            $translations = json_decode(File::get(lang_path("{$locale}.json")), true);
            $this->assertSame([], array_values(array_diff(array_keys($keys), array_keys($translations))), "{$locale}.json is missing keys");
        }
    }

    // ---- Dashboards and safety

    public function test_dashboards_show_real_counts(): void
    {
        $citizen = $this->user();
        $admin = $this->user('admin');
        $this->application($citizen, ['status' => 'correction_requested']);
        $this->application($citizen);

        $this->actingAs($citizen)->get('/dashboard')->assertOk()
            ->assertViewHas('needsCorrection', 1)->assertViewHas('inReview', 1);

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertViewHas('totalRequests', 2)->assertViewHas('pendingRequests', 1);
    }

    public function test_portal_shows_a_notice_instead_of_failing_before_the_migrations_run(): void
    {
        $admin = $this->user('admin');
        Schema::table('users', fn ($table) => $table->dropColumn('deactivated_at'));
        Platform::forgetSchemaState();

        $this->actingAs($admin)->get('/admin/dashboard')->assertStatus(503)->assertSee('Update Pending');
        $this->get('/')->assertOk();
    }

    public function test_privacy_and_terms_are_public_and_honest(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('not a government service');
        $this->get('/terms')->assertOk()->assertSee('It does not provide legally recognised notarisation');
    }
}
