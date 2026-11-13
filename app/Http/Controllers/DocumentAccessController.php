<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\NotaryRequest;
use Illuminate\Support\Facades\Gate;

/** Preview and demo certificate, for anyone allowed to view the application. */
class DocumentAccessController extends Controller
{
    public function preview(Document $document)
    {
        Gate::authorize('view', $document);

        return $document->previewResponse() ?? abort(404);
    }

    public function certificate(NotaryRequest $notaryRequest)
    {
        Gate::authorize('view', $notaryRequest);

        abort_unless($notaryRequest->status === NotaryRequest::STATUS_COMPLETED, 404);

        $notaryRequest->load(['documents', 'user', 'notary']);

        abort_if($notaryRequest->certificatesBlocked(), 404);

        return view('certificate.show', [
            'application' => $notaryRequest,
            'documents'   => $notaryRequest->validCertificates(),
            'withheld'    => $notaryRequest->withheldCertificates()->count(),
        ]);
    }
}
