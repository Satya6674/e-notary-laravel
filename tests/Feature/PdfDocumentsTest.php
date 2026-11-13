<?php

namespace Tests\Feature;

use App\Exceptions\PdfGenerationFailed;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\DocumentReports;
use App\Support\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mpdf\MpdfException;
use Tests\TestCase;

/** Real PDFs from the real engine, plus the HTML behind them to check what each one says. */
class PdfDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Keeps the source of each PDF, as finished text inside a PDF is encoded glyphs.
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
    }

    public function remember(string $html): string
    {
        return $this->seen[] = $html;
    }

    private function user(string $role = 'public'): User
    {
        return User::factory()->create([
            'role' => $role, 'email_verified_at' => now(), 'mobile_verified_at' => now(),
            'notary_approved_at' => $role === 'notary' ? now() : null,
        ]);
    }

    private function application(User $owner, array $values = []): NotaryRequest
    {
        return NotaryRequest::create($values + [
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $owner->id,
            'document_type' => 'affidavit', 'purpose' => 'Sample purpose', 'status' => 'pending',
        ]);
    }

    private function assertRealPdf(TestResponse $response): string
    {
        $response->assertOk();
        $body = $response->getContent();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertStringContainsString('%%EOF', substr($body, -32));
        $this->assertMatchesRegularExpression('/\/FontName \/[A-Z]+\+Mukta/', $body);

        return end($this->seen) ?: '';
    }

    private function pages(string $pdf): int
    {
        preg_match('/\/Type \/Pages.*?\/Count (\d+)/s', $pdf, $match);

        return (int) ($match[1] ?? 0);
    }

    public function test_summary_maps_real_values_and_keeps_staff_detail_from_citizens(): void
    {
        $owner = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($owner, [
            'status' => 'assigned', 'notary_id' => $notary->id, 'purpose' => 'Purchase of a house',
            'service_slug' => 'name-change-affidavit',
            'service_details' => ['service' => 'Name Change Affidavit', 'fields' => [['label' => 'Proposed name', 'value' => 'Asha Rao']]],
        ]);
        Document::create(['request_id' => $application->id, 'title' => 'Draft name change affidavit', 'requirement_key' => 'draft_affidavit', 'original_file' => 'documents/a.pdf', 'file_size' => 2048, 'file_hash' => str_repeat('a', 64)]);
        AuditLog::create(['user_id' => $notary->id, 'action' => 'request.correction_requested', 'model_type' => 'NotaryRequest', 'model_id' => $application->id, 'meta' => ['new_status' => 'correction_requested', 'actor_role' => 'notary', 'reason' => 'Please check the date']]);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.summary', $application)));

        foreach ([$application->request_number, 'Purchase of a house', 'Name Change Affidavit', 'Asha Rao', 'Draft name change affidavit', 'Please check the date', 'Assigned', '2.0 KB'] as $value) {
            $this->assertStringContainsString($value, $html);
        }

        // A required document that was never uploaded is shown as missing, not hidden.
        $this->assertStringContainsString('Document showing the current name', $html);
        $this->assertStringContainsString('Missing', $html);

        $this->assertStringContainsString('<td>Notary</td>', $html);
        $staffView = $this->assertRealPdf($this->actingAs($notary)->get(route('applications.summary', $application)));
        $this->assertStringContainsString('Reviewed By', $staffView);
    }

    public function test_receipt_has_a_stable_reference_and_cannot_read_as_approval(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['status' => 'rejected']);

        $first = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.receipt', $application)));
        $second = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.receipt', $application)));

        $reference = 'RCPT-'.substr($application->request_number, 3);
        $this->assertStringContainsString($reference, $first);
        $this->assertStringContainsString($reference, $second);
        $this->assertStringContainsString('Rejected', $first);
        $this->assertStringContainsString('not proof of notarisation, government approval or a completed service', $first);
        $this->assertStringNotContainsString('Approved', $first);
    }

    public function test_review_report_shows_recorded_actions_and_invents_none(): void
    {
        $owner = $this->user();
        $notary = $this->user('notary');
        $empty = $this->application($owner, ['notary_id' => $notary->id, 'status' => 'assigned']);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.review-report', $empty)));
        $this->assertStringContainsString('No status changes yet.', $html);
        $this->assertStringContainsString('None recorded', $html);
        $this->assertStringContainsString('Waiting for the assigned notary to record a decision.', $html);

        $decided = $this->application($owner, ['notary_id' => $notary->id, 'status' => 'rejected', 'decision_remarks' => 'Signature missing', 'decided_at' => now()]);
        AuditLog::create(['user_id' => $notary->id, 'action' => 'request.rejected', 'model_type' => 'NotaryRequest', 'model_id' => $decided->id, 'meta' => ['new_status' => 'rejected', 'actor_role' => 'notary', 'reason' => 'Signature missing']]);

        $citizen = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.review-report', $decided)));
        $this->assertStringContainsString('Signature missing', $citizen);
        $this->assertStringContainsString('No further action. This application is closed.', $citizen);
        $this->assertStringNotContainsString(e($notary->name), $citizen);

        $staff = $this->assertRealPdf($this->actingAs($notary)->get(route('applications.review-report', $decided)));
        $this->assertStringContainsString(e($notary->name), $staff);
    }

    public function test_appointment_pdf_never_presents_a_request_as_confirmed(): void
    {
        $owner = $this->user();
        $application = $this->application($owner);
        $appointment = Appointment::create(['request_id' => $application->id, 'user_id' => $owner->id, 'scheduled_at' => '2026-11-20 05:30:00', 'mode' => 'video', 'status' => 'requested']);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('appointments.pdf', $appointment)));

        $this->assertStringContainsString('Appointment Request', $html);
        $this->assertStringContainsString('not confirmed yet', $html);
        $this->assertStringNotContainsString('Appointment Confirmation', $html);
        $this->assertStringContainsString('20 Nov 2026, 11:00 IST (UTC+05:30)', $html);
        $this->assertStringContainsString($appointment->referenceNumber(), $html);

        $appointment->update(['status' => 'confirmed']);
        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('appointments.pdf', $appointment)));

        $this->assertStringContainsString('Appointment Confirmation', $html);
        $this->assertStringNotContainsString('not confirmed yet', $html);
    }

    public function test_appointment_pdf_is_limited_to_its_people(): void
    {
        $owner = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($owner, ['notary_id' => $notary->id, 'status' => 'assigned']);
        $appointment = Appointment::create(['request_id' => $application->id, 'user_id' => $owner->id, 'notary_id' => $notary->id, 'scheduled_at' => now()->addDay(), 'mode' => 'in_person']);
        $url = route('appointments.pdf', $appointment);

        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($this->user('notary'))->get($url)->assertForbidden();
        $this->actingAs($notary)->get($url)->assertOk();
        $this->actingAs($this->user('admin'))->get($url)->assertOk();
    }

    public function test_certificate_uses_the_canonical_url_with_a_qr_and_only_when_completed(): void
    {
        config(['app.url' => 'https://enotary.example']);

        $owner = $this->user();
        $application = $this->application($owner, ['status' => 'approved']);
        Storage::fake('local');
        Storage::disk('local')->put('documents/a.pdf', 'content');
        Document::create(['request_id' => $application->id, 'title' => 'Sample', 'original_file' => 'documents/a.pdf', 'file_hash' => hash('sha256', 'content'), 'document_number' => 'DOC-1', 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);

        $this->actingAs($owner)->get(route('applications.certificate-pdf', $application))->assertNotFound();

        $application->update(['status' => 'completed']);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.certificate-pdf', $application)));

        $this->assertStringContainsString('code="https://enotary.example/verify/ABCDEFGHJKLMNPQR"', $html);
        $this->assertStringContainsString('NOT LEGALLY VALID', $html);
        $this->assertStringNotContainsString('local test address', $html);
        $this->assertStringNotContainsString('storage', $html);
    }

    public function test_certificate_warns_when_the_link_is_a_local_address(): void
    {
        config(['app.url' => 'http://127.0.0.1:8001']);

        $owner = $this->user();
        $application = $this->application($owner, ['status' => 'completed']);
        Storage::fake('local');
        Storage::disk('local')->put('documents/a.pdf', 'content');
        Document::create(['request_id' => $application->id, 'title' => 'Sample', 'original_file' => 'documents/a.pdf', 'file_hash' => hash('sha256', 'content'), 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.certificate-pdf', $application)));

        $this->assertStringContainsString('local test address', $html);
    }

    public function test_every_application_pdf_blocks_other_people_and_guests(): void
    {
        $owner = $this->user();
        $notary = $this->user('notary');
        $application = $this->application($owner, ['notary_id' => $notary->id, 'status' => 'completed']);

        // A certificate PDF exists only for a record the public check would confirm.
        Storage::fake('local');
        Storage::disk('local')->put('documents/a.pdf', 'content');
        Document::create(['request_id' => $application->id, 'title' => 'Sample', 'original_file' => 'documents/a.pdf', 'file_hash' => hash('sha256', 'content'), 'verification_code' => 'ABCDEFGHJKLMNPQR', 'certified_at' => now()]);

        foreach (['applications.summary', 'applications.receipt', 'applications.review-report', 'applications.certificate-pdf'] as $route) {
            $url = route($route, $application);

            $this->actingAs($this->user())->get($url)->assertForbidden();
            $this->actingAs($this->user('notary'))->get($url)->assertForbidden();
            $this->actingAs($owner)->get($url)->assertOk();
            $this->actingAs($notary)->get($url)->assertOk();
            // Admins open application PDFs only within a fresh two-factor step-up (SEC-2); logging out
            // below ends the window, so each pass confirms again.
            $this->actingAs($this->user('admin'))->withSession([\App\Support\AdminReauth::SESSION_KEY => time()])->get($url)->assertOk();
            $this->post('/logout');
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_guessing_an_id_or_a_reference_reveals_nothing(): void
    {
        $owner = $this->user();
        $application = $this->application($owner);

        $this->actingAs($owner)->get('/applications/999999/receipt.pdf')->assertNotFound();
        $this->actingAs($owner)->get('/applications/'.$application->request_number.'/receipt.pdf')->assertNotFound();
        $this->actingAs($owner)->get('/appointments/999999/information.pdf')->assertNotFound();
    }

    public function test_download_and_preview_headers_are_safe(): void
    {
        $owner = $this->user();
        $application = $this->application($owner);

        $download = $this->actingAs($owner)->get(route('applications.receipt', $application));
        $this->assertSame('attachment; filename="enotary-'.strtolower($application->request_number).'-receipt.pdf"', $download->headers->get('content-disposition'));

        $preview = $this->actingAs($owner)->get(route('applications.receipt', [$application, 'preview' => 1]));
        $this->assertStringStartsWith('inline;', $preview->headers->get('content-disposition'));

        foreach ([$download, $preview] as $response) {
            $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
            $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
            $this->assertStringContainsString('sandbox', $response->headers->get('content-security-policy'));
            $this->assertSame((string) strlen($response->getContent()), $response->headers->get('content-length'));
        }
    }

    public function test_administrative_report_counts_every_record_and_keeps_notaries_to_their_own(): void
    {
        $owner = $this->user();
        $notary = $this->user('notary');
        $other = $this->user('notary');

        $mine = $this->application($owner, ['notary_id' => $notary->id, 'status' => 'assigned', 'service_details' => ['service' => 'Rent Agreement', 'fields' => []]]);
        $theirs = $this->application($owner, ['notary_id' => $other->id, 'status' => 'approved']);
        Appointment::create(['request_id' => $mine->id, 'user_id' => $owner->id, 'scheduled_at' => now()->addDay(), 'mode' => 'video']);

        $admin = $this->assertRealPdf($this->actingAs($this->user('admin'))->get(route('reports.pdf')));
        $this->assertStringContainsString($theirs->request_number, $admin);
        $this->assertStringContainsString('Notary Workload', $admin);
        $this->assertStringContainsString('Rent Agreement', $admin);
        $this->assertStringContainsString('Requested', $admin);

        $scoped = $this->assertRealPdf($this->actingAs($notary)->get(route('reports.pdf')));
        $this->assertStringContainsString($mine->request_number, $scoped);
        $this->assertStringNotContainsString($theirs->request_number, $scoped);
        $this->assertStringNotContainsString('Notary Workload', $scoped);

        $filtered = $this->assertRealPdf($this->actingAs($this->user('admin'))->get(route('reports.pdf', ['status' => 'approved'])));
        $this->assertStringContainsString($theirs->request_number, $filtered);
        $this->assertStringNotContainsString($mine->request_number, $filtered);

        $this->actingAs($owner)->get(route('reports.pdf'))->assertForbidden();
    }

    public function test_reports_with_no_records_still_produce_a_clear_document(): void
    {
        $response = $this->actingAs($this->user('admin'))->get(route('reports.pdf', ['status' => 'completed']));
        $html = $this->assertRealPdf($response);

        $this->assertStringContainsString('No applications match these filters.', $html);
        $this->assertSame(1, $this->pages($response->getContent()));
    }

    public function test_report_rejects_invalid_filters(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('reports.pdf', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
        $this->actingAs($admin)->get(route('admin.audit-logs.pdf', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
    }

    public function test_audit_report_filters_dates_and_never_prints_unlisted_metadata(): void
    {
        $admin = $this->user('admin');

        // Written at the old time: audit entries cannot be edited afterwards (migration 000023).
        $this->travelTo('2025-01-10 10:00:00');
        AuditLog::create(['user_id' => $admin->id, 'action' => 'test.old', 'meta' => ['changed' => ['name']]]);
        $this->travelBack();

        AuditLog::create(['user_id' => $admin->id, 'action' => 'test.recent', 'meta' => [
            'changed' => ['status'], 'token' => 'SECRET-TOKEN-VALUE', 'password' => 'SECRET-PASSWORD', 'otp' => '123456',
        ]]);

        $html = $this->assertRealPdf($this->actingAs($admin)->get(route('admin.audit-logs.pdf', ['from' => '2026-01-01'])));

        $this->assertStringContainsString('test.recent', $html);
        $this->assertStringContainsString('changed: status', $html);
        $this->assertStringNotContainsString('test.old', $html);
        $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', $html);
        $this->assertStringNotContainsString('SECRET-PASSWORD', $html);
        $this->assertStringNotContainsString('123456', $html);
    }

    public function test_large_reports_paginate_repeat_headers_and_state_the_row_limit(): void
    {
        $owner = $this->user();
        $long = str_repeat('Very long applicant name ', 8);
        $owner->update(['name' => substr($long, 0, 180)]);

        foreach (range(1, 120) as $i) {
            $this->application($owner);
        }

        $response = $this->actingAs($this->user('admin'))->get(route('reports.pdf'));
        $html = $this->assertRealPdf($response);

        $this->assertGreaterThan(3, $this->pages($response->getContent()));
        $this->assertStringContainsString('repeat_header="1"', $html);
        $this->assertSame(120, substr_count($html, 'NR-'));
    }

    public function test_long_values_print_unchanged_and_tables_wrap_rather_than_shrink(): void
    {
        $owner = $this->user();
        $long = str_repeat('UNBROKENTEXT', 30);
        $application = $this->application($owner, ['purpose' => $long]);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.summary', $application)));

        $this->assertStringContainsString($long, $html);
        $this->assertStringContainsString('table.fields { border-collapse: collapse; overflow: wrap;', $html);
    }

    public function test_document_types_use_correct_capitalisation(): void
    {
        $this->assertSame('Power of Attorney', app(DocumentReports::class)->label('power_of_attorney'));
    }

    public function test_generated_text_cannot_inject_markup_into_a_pdf(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['purpose' => '<barcode code="x" type="QR" /><img src="http://169.254.169.254/x">']);

        $html = $this->assertRealPdf($this->actingAs($owner)->get(route('applications.summary', $application)));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;barcode', $html);
    }

    public function test_hindi_and_punjabi_labels_and_fonts_are_used(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['purpose' => 'राम कुमार शर्मा ਰਾਮ ਕੁਮਾਰ Ram Kumar']);

        foreach (['hi' => ['आवेदन', 'Mukta'], 'pa' => ['ਅਰਜ਼ੀ', 'MuktaMahee']] as $locale => [$word, $font]) {
            $owner->update(['locale' => $locale]);

            $response = $this->actingAs($owner->fresh())->withSession(['locale' => $locale])->get(route('applications.summary', $application));
            $html = $this->assertRealPdf($response);

            $this->assertStringContainsString($word, $html);
            $this->assertMatchesRegularExpression("/\/FontName \/[A-Z]+\+{$font}/", $response->getContent());
        }
    }

    public function test_every_translated_character_exists_in_the_pdf_fonts(): void
    {
        foreach (['hi' => 'Mukta-Regular.ttf', 'pa' => 'MuktaMahee-Regular.ttf'] as $locale => $font) {
            $covered = $this->fontCharacters(resource_path("fonts/pdf/{$font}"));
            $text = implode('', json_decode(file_get_contents(lang_path("{$locale}.json")), true));
            $missing = [];

            foreach (array_unique(mb_str_split($text)) as $character) {
                $code = mb_ord($character);

                // Joiners shape text and are not drawn.
                if ($code > 0x20 && ! in_array($code, [0x200C, 0x200D], true) && ! isset($covered[$code])) {
                    $missing[$character] = sprintf('U+%04X', $code);
                }
            }

            $this->assertSame([], $missing, "{$font} lacks these characters, which would print as empty boxes");
        }
    }

    /** Reads the font's own Unicode table. Text extraction cannot show a missing glyph. */
    private function fontCharacters(string $file): array
    {
        $font = file_get_contents($file);
        $ushort = fn (int $at) => unpack('n', $font, $at)[1];
        $ulong = fn (int $at) => unpack('N', $font, $at)[1];
        $characters = [];

        for ($i = 0; $i < $ushort(4); $i++) {
            if (substr($font, 12 + $i * 16, 4) === 'cmap') {
                $cmap = $ulong(12 + $i * 16 + 8);

                for ($t = 0; $t < $ushort($cmap + 2); $t++) {
                    $table = $cmap + $ulong($cmap + 4 + $t * 8 + 4);

                    if ($ushort($table) === 4) {
                        $count = $ushort($table + 6) / 2;

                        for ($s = 0; $s < $count; $s++) {
                            $end = $ushort($table + 14 + $s * 2);
                            $start = $ushort($table + 16 + $count * 2 + $s * 2);

                            for ($code = $start; $code <= $end && $code < 0xFFFF; $code++) {
                                $characters[$code] = true;
                            }
                        }
                    }
                }
            }
        }

        return $characters;
    }

    public function test_engine_failure_gives_a_clear_message_and_logs_no_content(): void
    {
        $owner = $this->user();
        $application = $this->application($owner, ['purpose' => 'Private purpose text']);

        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer {
            public function render(string $view, array $data = [], array $options = []): string
            {
                throw new MpdfException('Engine failure with Private purpose text');
            }
        });
        $this->app->forgetInstance(DocumentReports::class);

        Log::spy();

        $this->actingAs($owner)->from(route('user.requests.show', $application))->get(route('applications.summary', $application))
            ->assertRedirect(route('user.requests.show', $application))
            ->assertSessionHas('error', 'This document could not be generated. Please try again.');

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'PDF generation failed'
            && $context === ['template' => 'summary', 'error' => 'MpdfException'])->once();
    }

    public function test_report_service_wraps_engine_errors(): void
    {
        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer {
            public function render(string $view, array $data = [], array $options = []): string
            {
                throw new MpdfException('Failure');
            }
        });

        $this->expectException(PdfGenerationFailed::class);

        $this->app->make(DocumentReports::class)->sampleDocument('affidavit', 'Sample');
    }

    public function test_each_download_is_audited_with_its_type(): void
    {
        $owner = $this->user();
        $application = $this->application($owner);

        $this->actingAs($owner)->get(route('applications.receipt', $application))->assertOk();

        $entry = AuditLog::where('action', 'report.exported')->latest('id')->firstOrFail();
        $this->assertSame('receipt', $entry->meta['report']);
        $this->assertSame($application->id, (int) $entry->model_id);
    }

    public function test_sample_document_is_marked_as_a_sample(): void
    {
        $pdf = app(DocumentReports::class)->sampleDocument('affidavit', 'Sample purpose');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('SAMPLE ONLY', end($this->seen));
    }
}
