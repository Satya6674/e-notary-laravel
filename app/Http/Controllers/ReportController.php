<?php

namespace App\Http\Controllers;

use App\Exceptions\PdfGenerationFailed;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\Analytics;
use App\Services\DocumentReports;
use App\Support\Platform;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reports and PDF downloads. A notary reports only on their own cases. */
class ReportController extends Controller
{
    private const HEADERS = ['Reference', 'Citizen', 'Type', 'Status', 'Notary', 'Submitted', 'Decided'];

    /** Rows printed in one PDF. The CSV export has no such limit, and the page says when it applies. */
    private const PDF_ROWS = 1000;

    public function __construct(private DocumentReports $reports)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->query($request, $filters);

        return view('reports.index', [
            'filters'  => $filters,
            'rows'     => (clone $query)->latest()->paginate(20)->withQueryString(),
            'byStatus' => (clone $query)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'total'    => (clone $query)->count(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->query($request, $this->filters($request))->latest()->limit(5000)->get();

        AuditLog::record('report.exported', null, ['format' => 'csv', 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADERS);

            foreach ($rows as $r) {
                fputcsv($out, array_map($this->safeCell(...), [
                    $r->request_number, (string) $r->user?->name, str_replace('_', ' ', $r->document_type), str_replace('_', ' ', $r->status),
                    (string) $r->notary?->name, (string) $r->created_at?->ist('Y-m-d'), (string) $r->decided_at?->ist('Y-m-d'),
                ]));
            }

            fclose($out);
        }, 'enotary-applications-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->query($request, $filters);
        $rows = (clone $query)->latest()->limit(self::PDF_ROWS)->get();
        $admin = $request->user()->isAdmin();

        AuditLog::record('report.exported', null, ['format' => 'pdf', 'report' => 'administrative', 'rows' => $rows->count()]);

        return $this->pdfResponse($request, 'enotary-administrative-report', fn () => $this->reports->applicationsReport(
            $rows, $filters, $admin ? 'All applications' : 'Assigned to you', $this->statistics($query, $admin)
        ));
    }

    /** Anyone who may view the application may download its summary. */
    public function applicationSummary(Request $request, NotaryRequest $notaryRequest)
    {
        return $this->applicationPdf($request, $notaryRequest, 'application_summary', 'summary',
            fn (bool $staff) => $this->reports->applicationSummary($notaryRequest, $staff));
    }

    public function applicationReceipt(Request $request, NotaryRequest $notaryRequest)
    {
        return $this->applicationPdf($request, $notaryRequest, 'receipt', 'receipt',
            fn () => $this->reports->receipt($notaryRequest));
    }

    public function reviewReport(Request $request, NotaryRequest $notaryRequest)
    {
        return $this->applicationPdf($request, $notaryRequest, 'review_report', 'review-report',
            fn (bool $staff) => $this->reports->reviewReport($notaryRequest, $staff));
    }

    public function certificatePdf(Request $request, NotaryRequest $notaryRequest)
    {
        Gate::authorize('view', $notaryRequest);

        abort_unless($notaryRequest->status === NotaryRequest::STATUS_COMPLETED, 404);
        abort_if($notaryRequest->certificatesBlocked(), 404);

        return $this->applicationPdf($request, $notaryRequest, 'certificate', 'certificate',
            fn () => $this->reports->certificate($notaryRequest));
    }

    /** The citizen who booked it, its notary, and administrators. */
    public function appointmentPdf(Request $request, Appointment $appointment)
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin()
            || $user->id === (int) $appointment->user_id
            || ($user->isApprovedNotary() && $user->id === (int) $appointment->notary_id),
            403
        );

        AuditLog::record('report.exported', $appointment, ['format' => 'pdf', 'report' => 'appointment']);

        return $this->pdfResponse($request, 'enotary-'.strtolower($appointment->referenceNumber()),
            fn () => $this->reports->appointment($appointment));
    }

    public function auditPdf(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date|after_or_equal:from',
        ]) + ['search' => null, 'from' => null, 'to' => null];

        $search = trim((string) $filters['search']);

        $query = AuditLog::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('action', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->when($this->dayBounds($filters['from'], null)[0], fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($this->dayBounds(null, $filters['to'])[1], fn ($q, $to) => $q->where('created_at', '<=', $to));

        $total = (clone $query)->count();
        $entries = $query->with('user')->latest('id')->limit(self::PDF_ROWS)->get();

        AuditLog::record('report.exported', null, ['format' => 'pdf', 'report' => 'audit', 'rows' => $entries->count()]);

        return $this->pdfResponse($request, 'enotary-audit-report', fn () => $this->reports->auditReport($entries, $filters, $total));
    }

    private function applicationPdf(Request $request, NotaryRequest $application, string $report, string $suffix, Closure $build)
    {
        Gate::authorize('view', $application);

        AuditLog::record('report.exported', $application, ['format' => 'pdf', 'report' => $report]);

        $staff = $request->user()->isAdmin() || $request->user()->isNotary();

        return $this->pdfResponse($request, 'enotary-'.strtolower($application->request_number).'-'.$suffix, fn () => $build($staff));
    }

    /** Add ?preview=1 to open in the browser. Generated text can never run as a page. */
    private function pdfResponse(Request $request, string $name, Closure $build)
    {
        try {
            $pdf = $build();
        } catch (PdfGenerationFailed) {
            return back()->with('error', 'This document could not be generated. Please try again.');
        }

        $disposition = $request->boolean('preview') ? 'inline' : 'attachment';

        return response($pdf, 200, [
            'Content-Type'            => 'application/pdf',
            'Content-Disposition'     => $disposition.'; filename="'.preg_replace('/[^a-z0-9-]/', '', $name).'.pdf"',
            'Content-Length'          => (string) strlen($pdf),
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; object-src 'self'",
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, no-store',
        ]);
    }

    /** Counted by the database across all matching records, not only the rows printed. */
    private function statistics(Builder $query, bool $admin): array
    {
        $ids = (clone $query)->reorder()->select('notary_requests.id');
        $services = (clone $query)->reorder()->setEagerLoads([])->get(['service_details', 'document_type']);

        return [
            'total'        => (clone $query)->count(),
            'byStatus'     => (clone $query)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status'),
            'byService'    => $services
                ->countBy(fn (NotaryRequest $r) => $r->service_details['service'] ?? $this->reports->label($r->document_type))
                ->sortDesc(),
            'workload'     => $admin ? $this->workload($query) : null,
            'appointments' => Appointment::whereIn('request_id', $ids)->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status'),
        ];
    }

    private function workload(Builder $query): array
    {
        $rows = (clone $query)->reorder()->setEagerLoads([])->whereNotNull('notary_id')
            ->selectRaw("notary_id, count(*) as total, sum(case when status in ('assigned', 'correction_requested') then 1 else 0 end) as open")
            ->groupBy('notary_id')->get();

        $names = User::whereIn('id', $rows->pluck('notary_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name'  => $names[$row->notary_id] ?? 'Deleted account',
            'open'  => (int) $row->open,
            'total' => (int) $row->total,
        ])->sortByDesc('total')->values()->all();
    }

    private function filters(Request $request): array
    {
        $data = array_merge(['status' => null, 'from' => null, 'to' => null], $request->validate([
            'status' => 'nullable|string|max:30',
            'from'   => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01',
            'to'     => 'nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:2100-12-31',
        ]));

        // The same limit as Admin > Analytics, so one request cannot load years of rows.
        if ($data['from'] && Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'] ?? now()->toDateString())) >= Analytics::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => __('Choose a range of at most two years.')]);
        }

        return $data;
    }

    /**
     * Dates are Indian calendar days, as on every page, while the database keeps UTC. A record made
     * at 01:30 IST on the 5th is stored on the 4th, so filter on the day's bounds converted to UTC.
     */
    private function dayBounds(?string $from, ?string $to): array
    {
        return [
            $from ? Carbon::parse($from, Platform::DISPLAY_TIMEZONE)->startOfDay()->utc() : null,
            $to ? Carbon::parse($to, Platform::DISPLAY_TIMEZONE)->endOfDay()->utc() : null,
        ];
    }

    private function query(Request $request, array $filters): Builder
    {
        $query = NotaryRequest::with(['user', 'notary']);

        if (! $request->user()->isAdmin()) {
            $query->where('notary_id', $request->user()->id);
        }

        if (array_key_exists((string) $filters['status'], NotaryRequest::TRANSITIONS)) {
            $query->where('status', $filters['status']);
        }

        [$from, $to] = $this->dayBounds($filters['from'], $filters['to']);

        return $query->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));
    }

    /** Stops a spreadsheet treating user text as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
