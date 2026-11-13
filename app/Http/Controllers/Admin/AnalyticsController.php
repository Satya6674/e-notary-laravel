<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PdfGenerationFailed;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Analytics;
use App\Services\DocumentReports;
use App\Support\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin analytics: live totals, registration and appointment trends, and the same figures
 * as CSV, Excel, PDF or a printed page. Reached only through the "admin" middleware group.
 */
class AnalyticsController extends Controller
{
    public function __construct(private Analytics $analytics)
    {
    }

    public function index(Request $request)
    {
        [$start, $end] = $this->dates($request);

        return view('admin.analytics.index', [
            'from'     => $start->toDateString(),
            'to'       => $end->toDateString(),
            'snapshot' => $this->analytics->snapshot(),
            'period'   => $this->analytics->period($start, $end),
            'maxDaily' => Analytics::MAX_DAILY_DAYS,
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$start, $end] = $this->dates($request);
        $tables = $this->tables($start, $end);

        AuditLog::record('report.exported', null, ['format' => 'csv', 'report' => 'analytics', 'from' => $start->toDateString(), 'to' => $end->toDateString()]);

        return response()->streamDownload(function () use ($tables) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads Hindi and Punjabi names correctly

            foreach ($tables as $title => $rows) {
                fputcsv($out, [$title]);

                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($cell) => is_string($cell) ? $this->safeCell($cell) : $cell, $row));
                }

                fputcsv($out, []);
            }

            fclose($out);
        }, $this->fileName($start, $end, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function xlsx(Request $request)
    {
        [$start, $end] = $this->dates($request);
        $book = new XlsxWriter;

        foreach ($this->tables($start, $end) as $title => $rows) {
            $book->sheet($title, $rows);
        }

        $body = $book->toString();

        AuditLog::record('report.exported', null, ['format' => 'xlsx', 'report' => 'analytics', 'from' => $start->toDateString(), 'to' => $end->toDateString()]);

        return response($body, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="'.$this->fileName($start, $end, 'xlsx').'"',
            'Content-Length'         => (string) strlen($body),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    public function pdf(Request $request, DocumentReports $reports)
    {
        [$start, $end] = $this->dates($request);

        AuditLog::record('report.exported', null, ['format' => 'pdf', 'report' => 'analytics', 'from' => $start->toDateString(), 'to' => $end->toDateString()]);

        try {
            $pdf = $reports->analyticsReport($start->toDateString(), $end->toDateString(), $this->tables($start, $end));
        } catch (PdfGenerationFailed) {
            return back()->with('error', 'This document could not be generated. Please try again.');
        }

        return response($pdf, 200, [
            'Content-Type'            => 'application/pdf',
            'Content-Disposition'     => ($request->boolean('preview') ? 'inline' : 'attachment').'; filename="'.$this->fileName($start, $end, 'pdf').'"',
            'Content-Length'          => (string) strlen($pdf),
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; object-src 'self'",
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, no-store',
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dates(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01',
            'to'   => 'nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:2100-12-31',
        ]);

        [$start, $end] = $this->analytics->range($data['from'] ?? null, $data['to'] ?? null);

        if ($start->greaterThan($end)) {
            // Only "from" was given and it is after today: show that single day.
            $end = $start->endOfDay();
        }

        if ($start->diffInDays($end) >= Analytics::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => __('Choose a range of at most two years.')]);
        }

        return [$start, $end];
    }

    /**
     * Every figure as rows of cells, one table per section. Used by all three exports, so the
     * files always match each other.
     *
     * @return array<string, list<list<scalar|null>>>
     */
    private function tables(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $s = $this->analytics->snapshot();
        $p = $this->analytics->period($start, $end);
        $na = __('Not available');
        $value = fn ($n) => $n === null ? $na : (int) $n;
        $status = fn (?array $counts, string $key) => $counts === null ? $na : (int) ($counts[$key] ?? 0);

        $tables[__('Summary')] = [
            [__('Figure'), __('Count')],
            [__('Report period'), $start->toDateString().' - '.$end->toDateString().' (IST)'],
            [__('Total Citizens'), $s['citizens']],
            [__('Total Notaries'), $s['notaries']],
            [__('Approved Notaries'), $s['approved_notaries']],
            [__('Pending Notaries'), $s['pending_notaries']],
            [__('Rejected Notaries'), $s['rejected_notaries']],
            [__('Deactivated accounts'), $s['deactivated']],
            [__('Total appointments'), $s['appointments']['total']],
            [__('Pending appointments'), $s['appointments']['pending']],
            [__('Completed appointments'), $s['appointments']['completed']],
            [__('Cancelled appointments'), $s['appointments']['cancelled']],
            [__('Deleted accounts'), $value($s['deleted_accounts'])],
            [__('Restored accounts'), $value($s['restored_accounts'])],
            [__('Recovery requests'), $status($s['recovery'], 'total')],
            [__('Recovery requests pending'), $status($s['recovery'], 'pending')],
            [__('Recovery requests approved'), $status($s['recovery'], 'approved')],
            [__('Recovery requests rejected'), $status($s['recovery'], 'rejected')],
            [__('Lost mailbox replacement requests'), $status($s['replacements'], 'total')],
            [__('Lost mailbox replacements completed'), $status($s['replacements'], 'verified')],
        ];

        $tables[__('Registrations')] = [
            [__('Figure'), __('Count')],
            [__('New registrations in period'), $p['registrations']['total']],
            [__('Citizens'), $p['registrations']['citizens']],
            [__('Notaries'), $p['registrations']['notaries']],
            [__('Notary approvals in period'), $p['decisions']['approved']],
            [__('Notary rejections in period'), $p['decisions']['rejected']],
        ];

        foreach (['daily' => __('Daily registrations'), 'weekly' => __('Weekly registrations'), 'monthly' => __('Monthly registrations')] as $key => $title) {
            if ($p['registrations'][$key] === null) {
                continue;
            }

            $rows = [[__('Period'), __('Citizens'), __('Notaries'), __('Total')]];

            foreach ($p['registrations'][$key] as $bucket) {
                $rows[] = [$bucket['label'], $bucket['citizens'], $bucket['notaries'], $bucket['citizens'] + $bucket['notaries']];
            }

            $tables[$title] = $rows;
        }

        $rows = [[__('Month'), __('Approved'), __('Rejected')]];

        foreach ($p['decisions']['monthly'] as $bucket) {
            $rows[] = [$bucket['label'], $bucket['approved'], $bucket['rejected']];
        }

        $tables[__('Notary decisions')] = $rows;

        $rows = [[__('Month'), __('Requested'), __('Confirmed'), __('Completed'), __('Cancelled'), __('Total')]];

        foreach ($p['appointments']['monthly'] as $bucket) {
            $rows[] = [$bucket['label'], $bucket['requested'], $bucket['confirmed'], $bucket['completed'], $bucket['cancelled'],
                $bucket['requested'] + $bucket['confirmed'] + $bucket['completed'] + $bucket['cancelled']];
        }

        $rows[] = [__('Completion rate'), $p['appointments']['completion'] === null ? $na : $p['appointments']['completion'].'%'];
        $tables[__('Appointments by month')] = $rows;

        $rows = [[__('Notary'), __('Total'), __('Open'), __('Completed'), __('Cancelled'), __('Completion rate')]];

        foreach ($p['appointments']['byNotary'] as $row) {
            $rows[] = [$row['name'], $row['total'], $row['open'], $row['completed'], $row['cancelled'], $row['completion'] === null ? $na : $row['completion'].'%'];
        }

        $tables[__('Appointments by notary')] = $rows;

        return $tables;
    }

    private function fileName(CarbonImmutable $start, CarbonImmutable $end, string $extension): string
    {
        return 'enotary-analytics-'.$start->format('Ymd').'-'.$end->format('Ymd').'.'.$extension;
    }

    /** Stops a spreadsheet treating user text as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
