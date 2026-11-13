<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailReplacement;
use App\Services\EmailReplacementService;

/**
 * The page behind the emailed link. Opening it changes nothing (mail scanners open links);
 * only the button does. The token stays in the link's own address and nowhere else: the page
 * sends no referrer, is not cached, and success redirects to a page without it.
 */
class EmailReplacementConfirmController extends Controller
{
    public function show(int $replacement, string $token)
    {
        $record = EmailReplacement::supported() ? EmailReplacement::find($replacement) : null;
        $usable = $record && hash_equals($record->token_hash, EmailReplacement::hashToken($token)) && $record->isPending() && ! $record->isExpired();

        return $this->private(response()->view('auth.email-replacement', ['usable' => $usable, 'replacement' => $replacement, 'token' => $token]));
    }

    public function confirm(int $replacement, string $token, EmailReplacementService $service)
    {
        abort_unless(EmailReplacement::supported(), 404);

        if ($service->confirm($replacement, $token) === EmailReplacementService::OK) {
            return redirect()->route('login')->with('status', 'Your new email address is confirmed. We have sent a link to it so you can set a new password.');
        }

        return $this->private(response()->view('auth.email-replacement', ['usable' => false, 'replacement' => $replacement, 'token' => $token], 410));
    }

    private function private($response)
    {
        return $response->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store, private');
    }
}
