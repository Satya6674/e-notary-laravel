<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\NotaryRequest;
use App\Services\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Public user side: list, track and correct the user's own applications.
 */
class RequestController extends Controller
{
    private const ADD_NEW = 'new';

    public function index(Request $http)
    {
        $status = $http->get('status', 'all');

        // Show only the requests belonging to the logged-in user, newest first.
        $query = NotaryRequest::with('documents')->where('user_id', Auth::id());

        if (array_key_exists($status, NotaryRequest::TRANSITIONS)) {
            $query->where('status', $status);
        }

        return view('user.requests', [
            'requests' => $query->latest()->paginate(10)->withQueryString(),
            'status'   => $status,
        ]);
    }

    public function show(NotaryRequest $notaryRequest)
    {
        Gate::authorize('view', $notaryRequest);

        // Load the attached documents so the page can list them.
        $notaryRequest->load(['documents', 'notary', 'appointments']);

        return view('user.request-show', [
            'request' => $notaryRequest,
            'history' => $notaryRequest->history(),
        ]);
    }

    /**
     * Which current file a corrected upload replaces: the one chosen, or the only one
     * there is. With several files the citizen must choose, as guessing could retire
     * the wrong document. With none, or by choosing to add one, the upload is a new first version.
     */
    private function documentToReplace(NotaryRequest $application, ?string $chosen): ?Document
    {
        // The citizen is adding a file the application lacked, not replacing one.
        if ($chosen === self::ADD_NEW) {
            return null;
        }

        $current = $application->currentDocuments()->get();

        if ($chosen !== null) {
            $document = ctype_digit($chosen) ? $current->firstWhere('id', (int) $chosen) : null;

            if (! $document) {
                throw ValidationException::withMessages(['replaces' => 'Choose a current document from this application.']);
            }

            return $document;
        }

        if ($current->count() > 1) {
            throw ValidationException::withMessages(['replaces' => 'Choose which document this file replaces.']);
        }

        return $current->first();
    }

    /** Takes back a pending application nobody has decided. Its files go with it. */
    public function withdraw(NotaryRequest $notaryRequest)
    {
        abort_unless((int) $notaryRequest->user_id === Auth::id(), 403);

        $documents = DB::transaction(function () use ($notaryRequest) {
            $locked = NotaryRequest::lockForUpdate()->findOrFail($notaryRequest->id);

            if (! $locked->canBeWithdrawn()) {
                return null;
            }

            $documents = $locked->documents()->get();

            foreach ($documents as $document) {
                AuditLog::record('document.deleted', $document, ['request_id' => $locked->id]);
                $document->delete();
            }

            $locked->cancelOpenAppointments();
            AuditLog::record('request.withdrawn', $locked);
            $locked->delete();

            return $documents;
        });

        if ($documents === null) {
            return back()->with('error', 'This application can no longer be withdrawn.');
        }

        $documents->each->deleteFile();

        return redirect()->route('user.requests')->with('status', 'Your application has been withdrawn.');
    }

    /** After a correction request: add the fixed file and/or a note, and send it back for review. */
    public function resubmit(Request $http, NotaryRequest $notaryRequest)
    {
        Gate::authorize('resubmit', $notaryRequest);

        $data = $http->validate([
            'note' => 'required|string|min:3|max:1000',
            'file' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx|extensions:pdf,jpg,jpeg,png,doc,docx',
            'replaces' => 'nullable|alpha_num|max:20',
        ]);

        $path = null;
        $replaced = $http->hasFile('file') ? $this->documentToReplace($notaryRequest, isset($data['replaces']) ? (string) $data['replaces'] : null) : null;

        try {
            DB::transaction(function () use ($http, $notaryRequest, $data, $replaced, &$path) {
                // Two tabs sending the same correction must not both pass the policy check above.
                $locked = NotaryRequest::lockForUpdate()->findOrFail($notaryRequest->id);
                abort_unless($locked->status === NotaryRequest::STATUS_CORRECTION, 403);

                if ($file = $http->file('file')) {
                    $hash = hash_file('sha256', $file->getRealPath());
                    $path = $file->store('documents', Document::PRIVATE_DISK);

                    $corrected = Document::create([
                        'request_id'           => $notaryRequest->id,
                        'title'                => $replaced?->title ?? 'Corrected document',
                        'requirement_key'      => $replaced?->requirement_key,
                        'original_file'        => $path,
                        'mime_type'            => $file->getMimeType(),
                        'file_size'            => $file->getSize(),
                        'file_hash'            => $hash,
                        'version'              => ($replaced?->version ?? 0) + 1,
                        'replaces_document_id' => $replaced?->id,
                    ]);

                    // The old row and file stay as history. They just stop being current.
                    if ($replaced) {
                        $replaced->forceFill(['superseded_at' => now()])->save();
                        AuditLog::record('document.superseded', $replaced, [
                            'request_id'   => $notaryRequest->id,
                            'replaced_by'  => $corrected->id,
                            'version'      => $replaced->version,
                            'certificated' => $replaced->verification_code !== null,
                        ]);
                    }
                }

                // Back to the same notary when there is one, otherwise to the admin queue.
                $next = $notaryRequest->notary_id ? NotaryRequest::STATUS_ASSIGNED : NotaryRequest::STATUS_PENDING;
                // decided_at stays: it is the last decision, and it keeps this application's files protected.
                $notaryRequest->forceStatus($next, [], $data['note']);
            });
        } catch (\Throwable $e) {
            if ($path && ! Document::isOnRecord($path)) {
                Storage::disk(Document::PRIVATE_DISK)->delete($path);
            }

            throw $e;
        }

        AuditLog::record('document.resubmitted', $notaryRequest);
        app(StaffNotifier::class)->correctionReturned($notaryRequest);

        return redirect()->route('user.requests.show', $notaryRequest)
            ->with('status', 'Your correction has been sent for review.');
    }
}
