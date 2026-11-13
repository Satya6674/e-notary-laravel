<?php

namespace App\Services;

use App\Exceptions\PdfGenerationFailed;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Support\PdfRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Builds every generated PDF. All content comes from stored records. */
class DocumentReports
{
    /** Audit details safe to print. Anything else in an entry stays off the page. */
    private const AUDIT_DETAILS = ['previous_status', 'new_status', 'override', 'service', 'report', 'format', 'rows', 'changed'];

    public function __construct(private PdfRenderer $renderer)
    {
    }

    public function applicationSummary(NotaryRequest $application, bool $staff): string
    {
        $application->loadMissing(['user', 'notary', 'documents', 'appointments']);

        return $this->pdf('summary', 'Application Summary', [
            'application' => $application,
            'staff'       => $staff,
            'meta'        => ['Reference' => $application->request_number, 'Generated' => $this->now()],
            'checklist'   => $this->checklist($application),
            'certificateProblem' => $application->hasCertificateProblem(),
            'history'     => $this->history($application, $staff),
        ]);
    }

    public function receipt(NotaryRequest $application): string
    {
        $application->loadMissing(['user', 'documents']);

        return $this->pdf('receipt', 'Application Submission Receipt', [
            'application' => $application,
            'meta'        => ['Receipt' => $application->receiptNumber(), 'Generated' => $this->now()],
            'certificateProblem' => $application->hasCertificateProblem(),
        ]);
    }

    public function reviewReport(NotaryRequest $application, bool $staff): string
    {
        $application->loadMissing(['user', 'notary', 'documents']);

        return $this->pdf('review', 'Document Review Report', [
            'application' => $application,
            'staff'       => $staff,
            'meta'        => ['Reference' => $application->request_number, 'Generated' => $this->now()],
            'history'     => $this->history($application, $staff),
            'nextAction'  => $this->nextAction($application),
            'certificateProblem' => $application->hasCertificateProblem(),
        ]);
    }

    public function appointment(Appointment $appointment): string
    {
        $appointment->loadMissing(['request', 'notary']);

        return $this->pdf('appointment', [
            'requested' => 'Appointment Request',
            'confirmed' => 'Appointment Confirmation',
            'completed' => 'Completed Appointment',
            'cancelled' => 'Cancelled Appointment',
        ][$appointment->status] ?? 'Appointment Information', [
            'appointment' => $appointment,
            'meta'        => ['Appointment' => $appointment->referenceNumber(), 'Generated' => $this->now()],
        ]);
    }

    public function certificate(NotaryRequest $application): string
    {
        $application->loadMissing(['user', 'notary', 'documents']);

        return $this->pdf('certificate', 'Review Certificate', [
            'application' => $application,
            'documents'   => $application->validCertificates(),
            'withheld'    => $application->withheldCertificates()->count(),
            'meta'        => ['Reference' => $application->request_number],
        ]);
    }

    /** @param  Collection<int, NotaryRequest>  $applications  the rows printed, already limited */
    public function applicationsReport(Collection $applications, array $filters, ?string $scope, array $statistics): string
    {
        return $this->pdf('applications', 'Administrative Report', [
            'applications' => $applications,
            'filters'      => $filters,
            'scope'        => $scope,
            'statistics'   => $statistics,
            'meta'         => ['Generated' => $this->now(), 'Records' => (string) $statistics['total']],
        ], landscape: true);
    }

    /** @param  Collection<int, AuditLog>  $entries  the rows printed, already limited */
    public function auditReport(Collection $entries, array $filters, int $total): string
    {
        return $this->pdf('audit', 'Audit Report', [
            'entries' => $entries,
            'filters' => $filters,
            'total'   => $total,
            'meta'    => ['Generated' => $this->now(), 'Entries' => (string) $total],
        ], landscape: true);
    }

    /** @param  array<string, list<list<scalar|null>>>  $tables  the same rows the CSV and Excel files hold */
    public function analyticsReport(string $from, string $to, array $tables): string
    {
        return $this->pdf('analytics', 'Analytics Report', [
            'tables' => $tables,
            'meta'   => ['Generated' => $this->now(), 'Period' => $from.' - '.$to],
        ], landscape: true);
    }

    public function sampleDocument(string $type, string $purpose): string
    {
        return $this->pdf('sample', 'Sample Document', [
            'type'    => $type,
            'purpose' => $purpose,
            'meta'    => ['Type' => __($this->label($type))],
        ]);
    }

    /** Short safe text for an audit entry: status changes and field names, never free text. */
    public function auditDetails(AuditLog $entry): string
    {
        $details = collect(array_intersect_key($entry->meta ?? [], array_flip(self::AUDIT_DETAILS)))
            ->reject(fn ($value) => blank($value) || $value === false)
            ->map(fn ($value, $key) => $key.': '.(is_array($value) ? implode(', ', $value) : (is_bool($value) ? 'yes' : $value)));

        return $details->implode('; ');
    }

    public function label(?string $value): string
    {
        return str_replace(' Of ', ' of ', ucwords(str_replace('_', ' ', (string) $value)));
    }

    public function role(?string $role): string
    {
        return ['public' => 'Citizen', 'notary' => 'Notary', 'admin' => 'Administrator'][$role] ?? 'System';
    }

    private function pdf(string $template, string $title, array $data, bool $landscape = false): string
    {
        try {
            return $this->renderer->render("pdf.{$template}", $data + ['title' => $title, 'report' => $this, 'landscape' => $landscape], [
                'title'     => __($title),
                'landscape' => $landscape,
            ]);
        } catch (Throwable $e) {
            Log::error('PDF generation failed', ['template' => $template, 'error' => class_basename($e)]);

            throw new PdfGenerationFailed($template, previous: $e);
        }
    }

    /** What the service asked for against what is on file, or just the files for an older application. */
    private function checklist(NotaryRequest $application): array
    {
        $service = $application->service_slug ? app(ServiceCatalogue::class)->find($application->service_slug) : null;
        $documents = $application->documents->whereNull('superseded_at')->keyBy(fn (Document $d) => $d->requirement_key ?: 'id-'.$d->id);
        $rows = [];

        foreach ($service['documents'] ?? [] as $requirement) {
            $document = $documents->pull($requirement['key']);
            $rows[] = [
                'name'     => $document->title ?? $requirement['name'],
                'required' => $requirement['required'],
                'document' => $document,
                'intact'   => $document?->fileMatchesRecord() ?? false,
            ];
        }

        foreach ($documents as $document) {
            $rows[] = ['name' => $document->title, 'required' => null, 'document' => $document, 'intact' => $document->fileMatchesRecord()];
        }

        return $rows;
    }

    private function history(NotaryRequest $application, bool $staff): Collection
    {
        return $application->history()->map(fn (AuditLog $entry) => [
            'when'     => $this->time($entry->created_at),
            'status'   => $this->label($entry->meta['new_status'] ?? Str::after($entry->action, 'request.')),
            'override' => ! empty($entry->meta['override']),
            'reason'   => $entry->meta['reason'] ?? null,
            'role'     => $entry->meta['actor_role'] ?? $entry->user?->role,
            'name'     => $staff ? ($entry->user?->name ?? 'Deleted account') : null,
        ]);
    }

    private function nextAction(NotaryRequest $application): string
    {
        if ($application->hasCertificateProblem()) {
            return 'An administrator needs to review this application.';
        }

        return [
            NotaryRequest::STATUS_PENDING    => 'Waiting for an administrator to assign a notary.',
            NotaryRequest::STATUS_ASSIGNED   => 'Waiting for the assigned notary to record a decision.',
            NotaryRequest::STATUS_CORRECTION => 'Waiting for the citizen to send a correction.',
            NotaryRequest::STATUS_APPROVED   => 'Waiting for the review to be marked complete.',
            NotaryRequest::STATUS_REJECTED   => 'No further action. This application is closed.',
            NotaryRequest::STATUS_COMPLETED  => 'No further action. This review is complete.',
        ][$application->status] ?? '';
    }

    private function time($value, string $format = 'd M Y, H:i'): ?string
    {
        return $value?->ist($format);
    }

    private function now(): string
    {
        return now()->ist('d M Y, H:i').' IST';
    }
}
