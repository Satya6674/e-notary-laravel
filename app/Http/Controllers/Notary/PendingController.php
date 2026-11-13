<?php

namespace App\Http\Controllers\Notary;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The page a Notary sees until an administrator approves the account. It shows the
 * person's own sign-up state only: no applications, no Citizen data and no work links.
 */
class PendingController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->isApprovedNotary()) {
            return redirect()->route('notary.dashboard');
        }

        $decision = $user->notaryDecision();

        return view('notary.pending', [
            'user'     => $user,
            'rejected' => $decision?->action === 'admin.notary_unapproved',
            'reason'   => $decision?->meta['reason'] ?? null,
            'decided'  => $decision?->created_at,
        ]);
    }
}
