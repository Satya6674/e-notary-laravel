<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminReauth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Admin step-up page: enter a code from the authenticator app (or a recovery code), then go
 * back and repeat the sensitive action. A password is not accepted here.
 */
class ReauthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! AdminReauth::canStepUp($request)) {
            return redirect()->route('admin.mfa.show')->with('error', 'Set up two-factor sign-in to use sensitive Admin actions.');
        }

        return view('admin.reauth', [
            'minutes' => (int) config('accounts.admin_reauth_minutes'),
            'fresh' => AdminReauth::fresh($request),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string|max:20']);

        $result = AdminReauth::verifyCode($request, (string) $request->input('code'));

        if ($result === 'not_enrolled') {
            return redirect()->route('admin.mfa.show')->with('error', 'Set up two-factor sign-in to use sensitive Admin actions.');
        }

        if (! in_array($result, ['ok', 'recovery'], true)) {
            return back()->withErrors(['code' => $result === 'locked'
                ? 'Too many incorrect codes. Please wait 15 minutes.'
                : 'That code is not valid.']);
        }

        $return = (string) $request->session()->pull('admin.reauth_return', route('admin.dashboard'));
        $input = (array) $request->session()->pull('admin.reauth_input', []);

        if (parse_url($return, PHP_URL_HOST) !== $request->getHost()) {
            $return = route('admin.dashboard');
        }

        return redirect()->to($return)->withInput($input)->with('success', 'Confirmed for '.config('accounts.admin_reauth_minutes').' minutes. Please repeat the action.'
            .($result === 'recovery' ? ' You used a recovery code; it cannot be used again.' : ''));
    }
}
