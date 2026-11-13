<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Notifications\NotaryRequestNotification;
use App\Services\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Public user side: upload, list, view, download and delete documents.
 *
 * Every uploaded document belongs to a NotaryRequest, and the request
 * belongs to the user. That is how ownership is checked below.
 * Files are stored on the private disk and only ever served through
 * these authenticated routes.
 */
class DocumentController extends Controller
{
    public function create()
    {
        return view('user.upload');
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'document_type' => 'required|in:affidavit,power_of_attorney,sale_deed,rent_agreement,will,declaration,undertaking,indemnity_bond,other',
            'description' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            // Restrict to document/image types (content-sniffed by the "mimes" rule).
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx|extensions:pdf,jpg,jpeg,png,doc,docx', // ~10MB; content AND name must agree
        ]);

        // Save the uploaded file first (outside the transaction) on the PRIVATE
        // disk, then create the request + document rows together.
        $hash = hash_file('sha256', $request->file('file')->getRealPath());
        $path = $request->file('file')->store('documents', Document::PRIVATE_DISK);

        try {
            $notaryRequest = DB::transaction(function () use ($user, $validated, $request, $path, $hash) {
                $notaryRequest = NotaryRequest::create([
                    'request_number' => NotaryRequest::generateRequestNumber(),
                    'user_id' => $user->id,
                    'document_type' => $validated['document_type'],
                    'purpose' => $validated['description'],
                    'status' => NotaryRequest::STATUS_PENDING,
                    'remarks' => $validated['notes'] ?? null,
                ]);

                $document = Document::create([
                    'request_id' => $notaryRequest->id,
                    'title' => $validated['title'],
                    'original_file' => $path,
                    'mime_type' => $request->file('file')->getMimeType(),
                    'file_size' => $request->file('file')->getSize(),
                    'file_hash' => $hash,
                ]);

                AuditLog::record('document.uploaded', $document, ['request_id' => $notaryRequest->id]);

                // Queued and marked afterCommit: dispatched only once the
                // transaction has committed, so no email for a rolled-back request.
                $user->notify(new NotaryRequestNotification($notaryRequest, 'submitted'));
                app(StaffNotifier::class)->applicationSubmitted($notaryRequest);

                return $notaryRequest;
            });
        } catch (\Throwable $e) {
            // Do not leave an orphan file behind if the database write failed.
            if (! Document::isOnRecord($path)) {
                Storage::disk(Document::PRIVATE_DISK)->delete($path);
            }

            throw $e;
        }

        return redirect()->route('user.documents')
            ->with('status', "Document uploaded. Your request reference is {$notaryRequest->request_number}. A confirmation email has been sent.");
    }

    public function index()
    {
        $user = Auth::user();

        $documents = Document::with('request')->withRecordFlags()
            ->whereHas('request', fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('user.documents', compact('documents'));
    }

    public function show(Document $document)
    {
        $this->authorizeOwner($document);

        return view('user.document-show', ['document' => $document]);
    }

    public function download(Document $document)
    {
        $this->authorizeOwner($document);

        return $document->downloadResponse()
            ?? redirect()->route('user.documents')->with('error', 'File not found for this document.');
    }

    public function destroy(Document $document)
    {
        $this->authorizeOwner($document);

        // The same row lock as a decision, so neither can slip past the other's checks.
        $refusal = DB::transaction(function () use ($document) {
            $application = NotaryRequest::lockForUpdate()->findOrFail($document->request_id);
            $document->setRelation('request', $application);

            // Users may only delete documents while the request is still pending;
            // once a notary is involved the file is part of the record.
            if ($application->status !== NotaryRequest::STATUS_PENDING) {
                return 'This document belongs to a request that is already under review and cannot be deleted.';
            }

            // A citizen's delete also erases the file, so the permanent record is out of reach.
            if ($document->isProtectedRecord()) {
                return 'This file is part of the application record, so it cannot be deleted.';
            }

            if ($refusal = $this->deletionRefusal($document)) {
                return $refusal;
            }

            AuditLog::record('document.deleted', $document, ['request_id' => $document->request_id]);
            $document->delete();

            // A request that loses its only document has nothing left to review, so it goes too.
            if ($application->currentDocuments()->doesntExist()) {
                $application->cancelOpenAppointments();
                AuditLog::record('request.withdrawn', $application);
                $application->delete();
            }

            return null;
        });

        if ($refusal) {
            return redirect()->route('user.documents')->with('error', $refusal);
        }

        $document->deleteFile();

        return redirect()->route('user.documents')->with('status', 'Document deleted successfully.');
    }

    /**
     * Deleting the last current file withdraws the application, which must not hide a
     * history. With other files still on it, a required one cannot go, or the application
     * could be completed without it. Withdraw on the application page removes it all.
     */
    private function deletionRefusal(Document $document): ?string
    {
        $application = $document->request;

        if ($application->currentDocuments()->whereKeyNot($document->id)->doesntExist()) {
            $history = $application->documents()->whereKeyNot($document->id)
                ->where(fn ($query) => $query->whereNotNull('superseded_at')->orWhereNotNull('verification_code'))->exists();

            return $history ? 'This file is part of the application record, so it cannot be deleted.' : null;
        }

        return $document->isRequiredByService() ? 'This document is required for this service, so it cannot be deleted.' : null;
    }

    private function authorizeOwner(Document $document): void
    {
        if (! $document->request || $document->request->user_id !== Auth::id()) {
            abort(403);
        }
    }
}
