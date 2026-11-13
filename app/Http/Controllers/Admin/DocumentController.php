<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin side: list, view, download and delete any uploaded document.
 * Downloads stream from the private disk through this authenticated route.
 */
class DocumentController extends Controller
{
    public function index(Request $request)
    {
        // The uploader, document type and status live on the related
        // notary request (the documents table only stores the file itself).
        $query = Document::with('request.user')->withRecordFlags();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('request', function ($r) use ($search) {
                        $r->where('document_type', 'like', "%{$search}%")
                            ->orWhere('request_number', 'like', "%{$search}%");
                    });
            });
        }

        $status = $request->get('status', 'all');
        if ($status !== 'all') {
            $query->whereHas('request', fn ($r) => $r->where('status', $status));
        }

        return view('admin.documents.index', [
            'documents' => $query->latest()->paginate(15)->withQueryString(),
            'search'    => $request->get('search', ''),
            'status'    => $status,
        ]);
    }

    public function show(Document $document)
    {
        $document->load('request.user');

        return view('admin.documents.show', compact('document'));
    }

    public function download(Document $document)
    {
        return $document->downloadResponse()
            ?? redirect()->route('admin.documents.index')->with('error', 'Document file not found.');
    }

    /**
     * Removes the document from view. The row and file are kept until the retention
     * period ends (enotary:purge-expired), and a decided record cannot be removed.
     */
    public function destroy(Request $request, Document $document)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        // The same row lock as a decision, so a removal cannot slip in behind an approval.
        $refusal = DB::transaction(function () use ($document, $data) {
            $application = NotaryRequest::withTrashed()->lockForUpdate()->find($document->request_id);
            $document->setRelation('request', $application);

            if (in_array($application?->status, [NotaryRequest::STATUS_APPROVED, NotaryRequest::STATUS_COMPLETED], true)) {
                return 'This document belongs to an approved or completed application, so it is kept as part of the record.';
            }

            // Removal hides a record and, in time, lets the purge erase it. The record stays.
            if ($document->isProtectedRecord()) {
                return 'This file is part of the application record, so it cannot be removed.';
            }

            // A required file gone from the review would let the application complete without it.
            if ($document->isRequiredByService()) {
                return 'This document is required for this service, so it cannot be removed.';
            }

            AuditLog::record('document.deleted', $document, ['request_id' => $document->request_id, 'reason' => $data['reason']]);
            $document->delete();

            return null;
        });

        if ($refusal) {
            return back()->with('error', $refusal);
        }

        return redirect()->route('admin.documents.index')
            ->with('status', 'Document removed. It is retained until the retention period ends.');
    }
}
