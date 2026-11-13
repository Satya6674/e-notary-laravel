<?php

namespace App\Http\Middleware;

use App\Support\AdminReauth;
use App\Support\SensitiveInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "admin.reauth" for sensitive Admin actions. Passes when the Admin completed a
 * fresh two-factor check within the step-up window, or when the request itself carries a correct
 * `step_up_code` (a code from the authenticator app, or a recovery code). A password is never
 * enough. Otherwise nothing is changed: the Admin is sent to the step-up page and repeats the
 * action afterwards.
 *
 * Applies to Admins only. On routes shared with Notaries and citizens (reports, previews,
 * application PDFs) everyone else passes straight through; their own access rules still apply.
 */
class RequireAdminReauth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin() || AdminReauth::fresh($request)) {
            return $next($request);
        }

        $message = 'For your security, enter a code from your authenticator app before this action. Nothing was changed.';

        if ($request->filled('step_up_code')) {
            $result = AdminReauth::verifyCode($request, (string) $request->input('step_up_code'));

            if (in_array($result, ['ok', 'recovery'], true)) {
                return $next($request);
            }

            if ($result !== 'not_enrolled') {
                $message = $result === 'locked'
                    ? 'Too many incorrect codes. Please wait 15 minutes. Nothing was changed.'
                    : 'That code is not valid. Nothing was changed.';

                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 423);
                }

                return back()->withErrors(['step_up_code' => $message])->withInput($request->except(SensitiveInput::FIELDS));
            }
        }

        // Fail closed: without two-factor sign-in there is no way to open the window.
        if (! AdminReauth::canStepUp($request)) {
            $message = 'Set up two-factor sign-in to use this action. Nothing was changed.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'setup_url' => route('admin.mfa.show')], 423);
            }

            return redirect()->route('admin.mfa.show')->with('error', $message);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'confirm_url' => route('admin.reauth.show')], 423);
        }

        // Once confirmed: a download or export link is opened again directly; any other action goes
        // back to the page it was started from, with what was typed (never passwords or codes), so
        // the Admin only presses the button again. Same host only.
        $return = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();
        $request->session()->put('admin.reauth_return', parse_url($return, PHP_URL_HOST) === $request->getHost() ? $return : route('admin.dashboard'));
        // Typed fields only: uploaded files cannot be kept and are chosen again.
        $request->session()->put('admin.reauth_input', $request->isMethod('GET') ? [] : Arr::except($request->input(), [...SensitiveInput::FIELDS, '_token', '_method']));

        return redirect()->route('admin.reauth.show')->with('status', $message);
    }
}
