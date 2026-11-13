<?php

namespace App\Http\Controllers\Auth;

use App\Support\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DeliveryLog;
use App\Services\SmsService;
use App\Services\StaffNotifier;
use App\Support\Platform;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a new account from the Sign Up form on the home page.
 *
 * Called by JavaScript (doRegister), so it returns JSON. After creation the
 * Registered event sends the 6-digit email verification code at once; the account stays
 * unverified until that code is entered. For a Citizen that completes registration (the
 * SMS mobile step is optional: config accounts.citizen_mobile_verification). Notary
 * accounts additionally wait for admin approval (users.notary_approved_at).
 */
class RegisterController extends Controller
{
    /** The verification code reached the mail service. */
    public const CODE_SENT = 'A verification code has been sent to your registered email address.';

    /** It did not: the account exists, and the code can be requested again on the next page. */
    public const CODE_NOT_SENT = "We couldn't send your verification code. Please try again later.";

    public function register(Request $request)
    {
        try {
            $request->merge([
                'mobile' => SmsService::normalizeMobile($request->input('mobile')) ?? $request->input('mobile'),
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => [
                    'required', 'email:rfc', 'max:255', 'unique:users,email',
                    // MariaDB compares without case, SQLite does not: check the same way on both.
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (is_string($value) && User::whereRaw('LOWER(email) = ?', [Str::lower($value)])->exists()) {
                            $fail('The email has already been taken.');
                        }
                    },
                ],
                'mobile' => 'required|string|digits:10|unique:users,mobile',
                // The shared password policy, with a matching confirmation.
                'password' => PasswordPolicy::rules(),
                'role' => 'required|in:public,notary',
                // Recorded with the versions in force, below, in the same transaction as the account.
                'accept_terms' => 'accepted',
            ], [
                'accept_terms.accepted' => 'Please accept the Terms of Use and the Privacy Policy to create an account.',
            ]);

            $user = new User([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
            ]);

            // Looked at once, after verification. See User::accountChoiceRoute().
            if (User::accountRecoverySupported()) {
                $user->previous_account_choice = User::CHOICE_CHECK;
            }

            // A deadlock with an administrator's action is brief, so the person never sees it.
            DB::transaction(function () use ($user) {
                $user->save();
                app(ConsentService::class)->record($user, 'registration');
            }, 3);

            // The account exists from here on. A failure below must not report the sign-up as
            // failed, or a retry would only say the email is taken.
            $mailQueued = true;

            try {
                // Sends the verification email (queued) via the Registered event.
                event(new Registered($user));
            } catch (\Throwable $e) {
                report($e);
                $mailQueued = false;
            }

            try {
                app(StaffNotifier::class)->accountRegistered($user);
            } catch (\Throwable $e) {
                // The account is listed under Notaries or Users either way.
                report($e);
            }

            Auth::login($user);
            $request->session()->regenerate();

            // What really happened to the verification email, from its delivery record. It was sent
            // at once (not queued), so the record is final here. "Sent" is said only when accepted.
            $delivery = $mailQueued && Platform::schemaReady()
                ? MessageDelivery::where(['user_id' => $user->id, 'channel' => 'mail', 'purpose' => 'verify_email'])->latest('id')->value('status')
                : null;

            // "Sent" only when the mail service accepted it. Anything else (Safe Preview, a block, a
            // failure, a missing setting) gets the same plain failure message; the reason is in the
            // delivery record and the Admin diagnostics, not in front of the citizen.
            return response()->json([
                'success' => true,
                'message' => $mailQueued && $delivery === MessageDelivery::ACCEPTED ? self::CODE_SENT : self::CODE_NOT_SENT,
                'redirect' => route('verification.notice'),
            ]);
        } catch (ValidationException $e) {
            // Invalid form input is a client error (422), not a server error (500).
            // Every problem is returned, so the person can fix them all in one go.
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
                'errors' => $e->validator->errors()->all(),
            ], 422);
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups at the same moment: the validation passed for both, the index for one.
            return response()->json([
                'success' => false,
                'message' => 'This email or mobile number is already registered.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            // Do not leak database or stack details to the browser.
            return response()->json([
                'success' => false,
                'message' => 'Registration failed because of a server error. Please try again later.',
            ], 500);
        }
    }
}
