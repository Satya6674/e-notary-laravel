<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMfa;
use App\Support\AdminReauth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin two-factor sign-in: setup, recovery codes, turning it off, and the sign-in challenge.
 * New recovery codes and turning it off sit behind the "admin.reauth" two-factor step-up; setup
 * itself asks for the password (see enroll()).
 */
class MfaController extends Controller
{
    public function __construct(private AdminMfa $mfa)
    {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $factor = AdminMfa::available() ? $this->mfa->factor($user) : null;
        $pendingSecret = $factor && ! $factor->isConfirmed() && $request->session()->get('admin_mfa.enrolling') === $user->getKey() ? $factor->secret : null;
        $uri = $pendingSecret ? AdminMfa::otpauthUri($user, $pendingSecret) : null;

        return $this->private(view('admin.mfa.show', [
            'available' => AdminMfa::available(),
            'stepUpFresh' => AdminReauth::fresh($request),
            'required' => AdminMfa::required(),
            'enrolled' => (bool) $factor?->isConfirmed(),
            'remaining' => $factor?->isConfirmed() ? $this->mfa->remainingRecoveryCodes($user) : 0,
            'pendingSecret' => $pendingSecret,
            'uri' => $uri,
            // Rendered here, on this server, as an image for the signed-in Admin only. Never emailed.
            'qr' => $uri ? AdminMfa::qrDataUri($uri) : null,
        ]));
    }

    /** Pages that show a setup key or recovery codes are never stored by the browser or a proxy. */
    private function private(View $view): Response
    {
        return response($view)->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')->header('Pragma', 'no-cache');
    }

    /**
     * Starts setup. An Admin who has no factor yet cannot pass a two-factor step-up, so the
     * password confirms instead: limited to 5 wrong tries per 15 minutes and audited, and it does
     * not open the step-up window. An already open window (from an earlier two-factor check) is
     * enough on its own.
     */
    public function enroll(Request $request): RedirectResponse
    {
        abort_unless(AdminMfa::available(), 503, 'Two-factor sign-in needs migration 2026_11_10_000031 first.');

        if (! AdminReauth::fresh($request)) {
            $request->validate(['current_password' => 'required|string|max:255'], ['current_password.required' => 'Enter your password to start setup.']);
            $result = AdminReauth::checkPassword($request, (string) $request->input('current_password'));

            if ($result !== 'ok') {
                return back()->withErrors(['current_password' => $result === 'locked'
                    ? 'Too many incorrect passwords. Please wait 15 minutes.'
                    : 'The password is incorrect.']);
            }
        }

        try {
            $this->mfa->start($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.mfa.show')->with('error', $e->getMessage());
        }

        $request->session()->put('admin_mfa.enrolling', $request->user()->getKey());

        return redirect()->route('admin.mfa.show')->with('status', 'Add the key to your authenticator app, then enter the 6-digit code it shows.');
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        abort_unless(AdminMfa::available(), 503);
        $data = $request->validate(['code' => 'required|digits:6']);

        $codes = $this->mfa->confirm($request->user(), $data['code']);

        if ($codes === null) {
            return back()->withErrors(['code' => 'That code is not valid. Check the time on your phone and try the newest code.']);
        }

        $request->session()->forget('admin_mfa.enrolling');
        // Enrolling proved the factor in this session, which also counts as a fresh step-up.
        AdminReauth::grantFromMfa($request);
        $request->session()->put(AdminMfa::SESSION_KEY, $request->user()->getKey());

        return $this->private(view('admin.mfa.recovery-codes', ['codes' => $codes, 'fresh' => true]));
    }

    public function regenerate(Request $request): Response|RedirectResponse
    {
        abort_unless(AdminMfa::available() && $this->mfa->enrolled($request->user()), 404);

        return $this->private(view('admin.mfa.recovery-codes', ['codes' => $this->mfa->regenerateRecoveryCodes($request->user()), 'fresh' => false]));
    }

    public function disable(Request $request): RedirectResponse
    {
        abort_unless(AdminMfa::available(), 404);
        $data = $request->validate(['code' => 'required|string|max:20']);

        // Checked first, so a refused request does not spend the code.
        if (AdminMfa::required()) {
            return back()->with('error', 'Two-factor sign-in is required for Admin accounts and cannot be turned off.');
        }

        $result = $this->mfa->verify($request->user(), $data['code']);

        if (! in_array($result, ['ok', 'recovery'], true)) {
            return back()->withErrors(['code' => $result === 'locked' ? 'Too many incorrect codes. Please wait 15 minutes.' : 'That code is not valid.']);
        }

        $this->mfa->disable($request->user(), $request->user());
        $request->session()->forget(AdminMfa::SESSION_KEY);

        return redirect()->route('admin.mfa.show')->with('success', 'Two-factor sign-in is off.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! AdminMfa::available() || ! $this->mfa->enrolled($user) || AdminMfa::passed($request, $user)) {
            return redirect()->to($user->landingUrl());
        }

        return view('admin.mfa.challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => 'required|string|max:20']);

        if (! AdminMfa::available() || ! $this->mfa->enrolled($user)) {
            return redirect()->to($user->landingUrl());
        }

        $result = $this->mfa->verify($user, $data['code']);

        if ($result === 'locked') {
            return back()->withErrors(['code' => 'Too many incorrect codes. Please wait 15 minutes, or sign out.']);
        }

        if ($result === 'invalid') {
            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        // Signing in with both factors counts as a fresh step-up. grantFromMfa() also gives the
        // session a new id once the second factor is passed (no fixation across the step).
        AdminReauth::grantFromMfa($request);
        $request->session()->put(AdminMfa::SESSION_KEY, $user->getKey());

        $redirect = redirect()->to($user->landingUrl());

        return $result === 'recovery'
            ? $redirect->with('status', 'You used a recovery code. '.$this->mfa->remainingRecoveryCodes($user).' remain. Create new ones under Two-factor sign-in if you are running low.')
            : $redirect;
    }
}
