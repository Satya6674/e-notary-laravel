<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles login and logout for all roles (public user, notary, admin).
 *
 * Login is called by JavaScript on the home page (doLogin), so it
 * returns JSON instead of a normal redirect. The route is rate limited
 * in routes/web.php.
 */
class LoginController extends Controller
{
    public function login(Request $request)
    {
        // Both fields must be filled in. The "mobile" field may also hold an email.
        $credentials = $request->validate([
            'mobile' => 'required|string',
            'password' => 'required|string',
        ]);

        // Decide which database column to match: email or mobile.
        $identifier = trim($credentials['mobile']);
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'mobile';

        // Registration stores the 10 significant digits ("+91 98765 43210" ->
        // 9876543210), so apply the same normalisation here or that user could
        // never log in with the number as they typed it at sign-up.
        if ($field === 'mobile') {
            $identifier = SmsService::normalizeMobile($identifier) ?? $identifier;
        }

        // A deactivated account, or one waiting for a new password, gets the same reply as a wrong
        // password and is refused before the login happens, so no login (and logout) is recorded.
        if (! Auth::attemptWhen([$field => $identifier, 'password' => $credentials['password']],
            fn (User $user) => ! $user->isDeactivated() && ! $user->mustResetPassword())) {
            return response()->json([
                'success' => false,
                'message' => __('Invalid login credentials'),
            ], 401);
        }

        // Give the user a fresh session ID after login (security best practice).
        $request->session()->regenerate();

        // A password sign-in starts from nothing: no two-factor pass, no step-up window and no
        // half-finished setup carries over from whoever used this browser session before.
        $request->session()->forget([
            \App\Services\AdminMfa::SESSION_KEY, \App\Support\AdminReauth::SESSION_KEY, \App\Support\AdminReauth::LEGACY_KEY,
            'admin_mfa.enrolling', 'admin.reauth_return', 'admin.reauth_input',
        ]);

        // Pending sign-up step first (email link, then SMS code for citizens),
        // otherwise the role's dashboard. route() keeps the path correct at a
        // domain root or in a sub-folder.
        return response()->json(['success' => true, 'redirect' => Auth::user()->landingUrl()]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        // Clear the session and refresh the CSRF token so the old session cannot be reused.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
