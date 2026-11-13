<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;

/**
 * Public lookup of demonstration certificates, by code or QR link. It reads the
 * database and shows no personal details. Every result says it is a demo record.
 */
class VerificationController extends Controller
{
    public function index(Request $request)
    {
        $code = $request->query('code');

        if (is_string($code) && ($code = trim($code)) !== '') {
            return redirect()->route('verify.show', strtoupper($code));
        }

        return view('verify.index');
    }

    public function show(string $code)
    {
        $code = strtoupper($code);

        $document = preg_match('/^[A-Z0-9]{16}$/', $code)
            ? Document::withTrashed()->with(['request' => fn ($q) => $q->withTrashed()])->where('verification_code', $code)->first()
            : null;

        return view('verify.result', ['code' => $code, 'document' => $document, 'state' => $document?->certificateState() ?? 'invalid']);
    }
}
