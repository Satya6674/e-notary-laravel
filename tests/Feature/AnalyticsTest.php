<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\EmailReplacement;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\Analytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * Module 9: Admin analytics. Every figure is checked against records made here, so a
 * hard-coded or mis-counted number fails. Every account is synthetic.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $role = 'public', array $extra = []): User
    {
        $this->n++;
        $user = User::create([
            'name' => "Demo Person {$this->n}", 'email' => "analytics{$this->n}@example.test", 'mobile' => '93000'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now()] + $extra)->save();

        return $user;
    }

    private function appointment(User $citizen, ?User $notary, string $status, $when): Appointment
    {
        $application = NotaryRequest::create([
            'request_number' => 'REQ-AN-'.(++$this->n), 'user_id' => $citizen->id, 'notary_id' => $notary?->id,
            'document_type' => 'affidavit', 'purpose' => 'Analytics test', 'status' => NotaryRequest::STATUS_ASSIGNED,
        ]);

        return Appointment::create([
            'request_id' => $application->id, 'user_id' => $citizen->id, 'notary_id' => $notary?->id,
            'scheduled_at' => $when, 'mode' => 'video', 'status' => $status,
        ]);
    }

    public function test_only_an_administrator_can_open_or_export_analytics(): void
    {
        $citizen = $this->user();
        $notary = $this->user('notary', ['notary_approved_at' => now()]);
        $routes = ['admin.analytics.index', 'admin.analytics.csv', 'admin.analytics.xlsx', 'admin.analytics.pdf'];

        // Guests first: actingAs() lasts for the rest of the test.
        foreach ($routes as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }

        foreach ($routes as $route) {
            $this->actingAs($citizen)->get(route($route))->assertForbidden();
            $this->actingAs($notary)->get(route($route))->assertForbidden();
        }

        $this->assertSame(0, AuditLog::where('action', 'report.exported')->count());
    }

    public function test_totals_match_the_records(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->user();
        $this->user();
        $this->user('public', ['deactivated_at' => now()]);
        $approved = $this->user('notary', ['notary_approved_at' => now()]);
        $this->user('notary');
        $rejected = $this->user('notary');

        $this->actingAs($admin)->post(route('admin.notaries.reject', $rejected), ['reason' => 'Licence number could not be checked.'])->assertRedirect();

        $this->appointment($c1, $approved, 'requested', now()->addDay());
        $this->appointment($c1, $approved, 'confirmed', now()->addDays(2));
        $this->appointment($c1, $approved, 'completed', now()->subDay());
        $this->appointment($c1, $approved, 'completed', now()->subDays(2));
        $this->appointment($c1, $approved, 'cancelled', now()->subDays(3));

        $s = app(Analytics::class)->snapshot();

        $this->assertSame(3, $s['citizens']);
        $this->assertSame(3, $s['notaries']);
        $this->assertSame(1, $s['approved_notaries']);
        $this->assertSame(1, $s['pending_notaries']);
        $this->assertSame(1, $s['rejected_notaries']);
        $this->assertSame(1, $s['deactivated']);
        $this->assertSame(['total' => 5, 'pending' => 2, 'requested' => 1, 'confirmed' => 1, 'completed' => 2, 'cancelled' => 1], $s['appointments']);

        $this->actingAs($admin)->get(route('admin.analytics.index'))->assertOk()
            ->assertSee('Reports &amp; Analytics', false)
            ->assertSee('Rejected Notaries')
            ->assertSee('Notary-wise statistics')
            ->assertSee($approved->name);
    }

    public function test_an_approval_after_a_rejection_counts_as_approved(): void
    {
        $admin = $this->user('admin');
        $notary = $this->user('notary');

        $this->actingAs($admin)->post(route('admin.notaries.reject', $notary), ['reason' => 'Licence number could not be checked.']);
        $this->assertTrue($notary->fresh()->isRejectedNotary());
        $this->assertSame(1, app(Analytics::class)->snapshot()['rejected_notaries']);

        $this->actingAs($admin)->post(route('admin.notaries.approve', $notary));
        $s = app(Analytics::class)->snapshot();

        $this->assertSame(1, $s['approved_notaries']);
        $this->assertSame(0, $s['rejected_notaries']);
        $this->assertSame(0, $s['pending_notaries']);
        $this->assertFalse($notary->fresh()->isRejectedNotary());
    }

    public function test_registrations_are_grouped_by_the_indian_day_and_filtered_by_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

        // 19:00 UTC on 10 Oct is 00:30 IST on 11 Oct, so it belongs to 11 Oct.
        $late = $this->user();
        $late->forceFill(['created_at' => CarbonImmutable::parse('2026-10-10 19:00:00', 'UTC')])->save();
        $notary = $this->user('notary');
        $notary->forceFill(['created_at' => CarbonImmutable::parse('2026-10-11 05:00:00', 'UTC')])->save();
        $outside = $this->user();
        $outside->forceFill(['created_at' => CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC')])->save();
        $this->user('admin'); // created "now", inside the range, but administrators are not registrations

        $analytics = app(Analytics::class);
        [$start, $end] = $analytics->range('2026-10-01', '2026-10-20');
        $period = $analytics->period($start, $end);

        $this->assertSame(2, $period['registrations']['total']);
        $this->assertSame(1, $period['registrations']['citizens']);
        $this->assertSame(1, $period['registrations']['notaries']);

        $daily = collect($period['registrations']['daily'])->keyBy('label');
        $this->assertCount(20, $daily);
        $this->assertSame(['label' => '11 Oct', 'citizens' => 1, 'notaries' => 1], $daily['11 Oct']);
        $this->assertSame(0, $daily['10 Oct']['citizens']);

        $this->assertSame([['label' => 'Oct 2026', 'citizens' => 1, 'notaries' => 1]], $period['registrations']['monthly']);
        $this->assertSame(2, array_sum(array_map(fn ($w) => $w['citizens'] + $w['notaries'], $period['registrations']['weekly'])));
    }

    public function test_daily_figures_are_left_out_of_long_ranges(): void
    {
        $analytics = app(Analytics::class);
        [$start, $end] = $analytics->range('2026-01-01', '2026-06-30');
        $period = $analytics->period($start, $end);

        $this->assertNull($period['registrations']['daily']);
        $this->assertCount(6, $period['registrations']['monthly']);
    }

    public function test_appointment_statistics_per_notary_and_completion_rate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC'));
        $citizen = $this->user();
        $a = $this->user('notary', ['notary_approved_at' => now()]);
        $b = $this->user('notary', ['notary_approved_at' => now()]);

        $this->appointment($citizen, $a, 'completed', '2026-10-05 06:00:00');
        $this->appointment($citizen, $a, 'completed', '2026-10-06 06:00:00');
        $this->appointment($citizen, $a, 'cancelled', '2026-10-07 06:00:00');
        $this->appointment($citizen, $a, 'requested', '2026-10-21 06:00:00');
        $this->appointment($citizen, $b, 'confirmed', '2026-10-08 06:00:00');
        $this->appointment($citizen, $b, 'completed', '2026-08-01 06:00:00'); // outside the range

        $analytics = app(Analytics::class);
        [$start, $end] = $analytics->range('2026-10-01', '2026-10-31');
        $appointments = $analytics->period($start, $end)['appointments'];

        $this->assertSame(5, $appointments['total']);
        $this->assertSame(['requested' => 1, 'confirmed' => 1, 'completed' => 2, 'cancelled' => 1], $appointments['byStatus']);
        $this->assertSame(66.7, $appointments['completion']);

        $rows = collect($appointments['byNotary'])->keyBy('name');
        $this->assertSame(['name' => $a->name, 'total' => 4, 'open' => 1, 'completed' => 2, 'cancelled' => 1, 'completion' => 66.7], $rows[$a->name]);
        $this->assertSame(1, $rows[$b->name]['total']);
        $this->assertNull($rows[$b->name]['completion']);

        $october = collect($analytics->period($start, $end)['appointments']['monthly'])->firstWhere('label', 'Oct 2026');
        $this->assertSame(['label' => 'Oct 2026', 'requested' => 1, 'confirmed' => 1, 'completed' => 2, 'cancelled' => 1], $october);
    }

    public function test_figures_waiting_for_a_migration_are_not_reported_as_zero(): void
    {
        $s = app(Analytics::class)->snapshot();

        foreach (['deleted_accounts' => User::softDeleteSupported(), 'recovery' => User::accountRecoverySupported(), 'replacements' => EmailReplacement::supported()] as $key => $supported) {
            $supported ? $this->assertNotNull($s[$key], $key) : $this->assertNull($s[$key], $key);
        }
    }

    public function test_csv_and_excel_hold_the_same_figures_and_are_safe(): void
    {
        $admin = $this->user('admin');
        $citizen = $this->user();
        $notary = $this->user('notary', ['notary_approved_at' => now(), 'name' => '@SUM(1+1)']);
        $this->appointment($citizen, $notary, 'completed', now()->subDay());

        $csv = $this->actingAs($admin)->get(route('admin.analytics.csv'));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('"Total Citizens",1', $body);
        $this->assertStringContainsString("'@SUM(1+1)", $body);
        $this->assertStringNotContainsString("\n@SUM", $body);

        $xlsx = $this->actingAs($admin)->get(route('admin.analytics.xlsx'));
        $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $xlsx->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('name="Summary"', $workbook);
        $this->assertStringContainsString('name="Appointments by notary"', $workbook);
        $sheets = '';
        for ($i = 1; ($sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml")) !== false; $i++) {
            $this->assertNotFalse(simplexml_load_string($sheet), "sheet {$i} is well-formed XML");
            $sheets .= $sheet;
        }
        $zip->close();
        @unlink($path);

        // Text stays text: no formula cells anywhere, and counts are real numbers.
        $this->assertStringNotContainsString('<f>', $sheets);
        $this->assertStringContainsString('@SUM(1+1)', $sheets);
        $this->assertMatchesRegularExpression('#<t xml:space="preserve">Total Citizens</t></is></c><c r="B\d+"><v>1</v>#', $sheets);

        $this->assertSame(2, AuditLog::where('action', 'report.exported')->get()->where('meta.report', 'analytics')->count());
    }

    public function test_pdf_export_renders(): void
    {
        $admin = $this->user('admin');

        $pdf = $this->actingAs($admin)->get(route('admin.analytics.pdf'));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // The page body must not overwrite the layout's title, and every PDF carries the legal-validity notice once (footer).
        $html = app(\App\Support\PdfRenderer::class)->html('pdf.analytics', [
            'title' => 'Analytics Report', 'report' => app(\App\Services\DocumentReports::class), 'landscape' => true,
            'meta' => ['Period' => 'x'], 'tables' => ['Summary' => [['Figure', 'Count'], ['Total Citizens', 1]]],
        ]);
        $this->assertStringContainsString('<div class="doc-title">Analytics Report</div>', $html);
        $this->assertSame(1, substr_count($html, \App\Support\Platform::PDF_VALIDITY_NOTICE));
        $this->assertStringNotContainsString('DEMO / NOT LEGALLY VALID', $html);
        $this->assertStringNotContainsString(\App\Support\Platform::PROTOTYPE_NOTICE, $html);
    }

    public function test_bad_dates_are_refused_with_a_message(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->from(route('admin.analytics.index'))
            ->get(route('admin.analytics.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertRedirect(route('admin.analytics.index'))->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('admin.analytics.index'))
            ->get(route('admin.analytics.index', ['from' => '2020-01-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('from');

        $this->actingAs($admin)->get(route('admin.analytics.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }
}
