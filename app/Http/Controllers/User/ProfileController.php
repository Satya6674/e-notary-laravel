<?php

namespace App\Http\Controllers\User;

use App\Support\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Public user side: edit own name, email, mobile and password.
 * Changing the email address un-verifies it and sends a new verification link.
 */
class ProfileController extends Controller
{
    public function edit()
    {
        /** @var User $user */
        $user = User::findOrFail(Auth::id());

        return view('user.profile', compact('user'));
    }

    public function update(Request $request)
    {
        /** @var User $user */
        $user = User::findOrFail(Auth::id());

        // An array here would break normalizeMobile(), so only a string reaches it.
        $typed = $request->input('mobile');
        $request->merge(['mobile' => SmsService::normalizeMobile(is_string($typed) ? $typed : null) ?? $typed]);

        // Email is NOT NULL in the database, so it stays required here.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => PasswordPolicy::rules(required: false),
        ]);

        // The number is a recovery channel: it changes only through MobileChangeController,
        // which asks for the password and proves the new number first.
        if ($request->filled('mobile') && $request->input('mobile') !== $user->mobile) {
            return back()->withInput($request->except('password', 'password_confirmation', 'current_password'))
                ->withErrors(['mobile' => 'Use the Change mobile number section to change your number.']);
        }

        $previousEmail = (string) $user->email;
        $emailChanged = strcasecmp($validated['email'], $previousEmail) !== 0;

        // A stolen session must not be able to take the account over.
        if (($emailChanged || ! empty($validated['password'])) && ! Hash::check((string) $request->input('current_password'), $user->password)) {
            return back()->withInput($request->except('password', 'password_confirmation', 'current_password'))
                ->withErrors(['current_password' => 'Enter your current password to change your email or password.']);
        }

        if (! empty($validated['password']) && $user->isRetiredPassword($validated['password'])) {
            return back()->withInput($request->except('password', 'password_confirmation', 'current_password'))
                ->withErrors(['password' => 'Choose a different password. Your previous password cannot be used again.']);
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        // Names of the changed fields only, never their values.
        $changed = array_keys($user->getDirty());

        $user->save();

        if (in_array('password', $changed, true)) {
            $user->notify(new SecurityAlertNotification('password_changed'));
        }

        if ($changed) {
            AuditLog::record('user.profile_updated', $user, ['changed' => $changed]);
        }

        if ($emailChanged) {
            // To the old address: it is the only place a thief cannot also read.
            Notification::route('mail', $previousEmail)->notify(new SecurityAlertNotification('email_changed'));
            $sent = $this->sendVerificationCode($user);

            return redirect()->route('verification.notice')
                ->with($sent ? 'status' : 'error', $sent
                    ? 'Profile updated. Please verify your new email address with the code we just sent to it.'
                    : "Profile updated, but we couldn't send a code to the new address. Use \"Send a new code\" on this page.");
        }

        return redirect()->route('user.profile')->with('status', 'Profile updated successfully.');
    }

    /**
     * The code for a new address, sent at once, and whether it truly left: "sent" is said only when
     * the mail service accepted it (or, in Safe Preview, when the preview recorded it). With the local
     * email bridge the mobile page confirms the address instead, as before.
     */
    private function sendVerificationCode(User $user): bool
    {
        if (\App\Services\MobileOtpEmailBridge::enabled() && ! $user->isAdmin()) {
            $user->sendEmailVerificationNotification();

            return true;
        }

        ['result' => $result, 'delivery' => $delivery] = $user->sendEmailVerification(request()->ip());

        return $result === \App\Services\OtpService::ISSUED
            && in_array($delivery, [\App\Models\MessageDelivery::ACCEPTED, \App\Models\MessageDelivery::SIMULATED], true);
    }
}
