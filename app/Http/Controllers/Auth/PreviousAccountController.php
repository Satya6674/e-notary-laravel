<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Notifications\AccountRecoveryCodeNotification;
use App\Services\AccountRecoveryService;
use App\Services\DeliveryLog;
use App\Services\OtpService;
use App\Support\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Welcome Back: shown only after identity checks, to someone whose proven email or mobile
 * belongs to a deleted account. Nothing about the old account is shown here. Every route
 * re-checks the person's state on the server, so none can be reached out of order.
 */
class PreviousAccountController extends Controller
{
    private function choosing(User $user): bool
    {
        return $user->accountChoiceRoute() === 'account.previous';
    }

    private function waiting(User $user): bool
    {
        return $user->accountChoiceRoute() === 'account.recovery.status';
    }

    private function leave(User $user)
    {
        return redirect()->route($user->nextVerificationRoute() ?? $user->accountChoiceRoute() ?? $this->homeFor($user));
    }

    /** Anything short of a real production site, where a check here proves nothing about ownership. */
    private function local(): bool
    {
        return ! app()->isProduction();
    }

    public function show(Request $request)
    {
        $user = $request->user();

        return $this->choosing($user) ? view('auth.previous-account', ['local' => $this->local()]) : $this->leave($user);
    }

    public function startNew(Request $request, AccountRecoveryService $recovery)
    {
        $user = $request->user();

        if (! $this->choosing($user)) {
            return $this->leave($user);
        }

        $recovery->chooseNewAccount($user);

        return redirect()->route($this->homeFor($user))->with('status', 'Your new account is ready. Your previous account stays archived.');
    }

    /**
     * Fresh proof, separate from registration. The code is typed in, never filled in.
     *
     * It always goes to the previous account's own email address. A mobile number alone never
     * authorizes a recovery: numbers are reissued, so whoever holds the number now may be someone
     * else. Found by email, that address is this person's own; found by mobile, it is the old
     * account's address, which only its owner can read.
     */
    public function code(Request $request, AccountRecoveryService $recovery, OtpService $otp)
    {
        $user = $request->user();

        if (! $this->choosing($user) || ! ($deletion = $recovery->detect($user))) {
            return $this->leave($user);
        }

        return view('auth.recovery-code', [
            'local' => $this->local(),
            'safePreview' => Platform::mailMode() === 'safe_preview',
            'destination' => DeliveryLog::maskEmail($deletion->email),
            'previousAddress' => ! $recovery->matchedByEmail($user, $deletion),
            'otp' => $otp->status((string) $user->mobile, OtpService::PURPOSE_RECOVERY),
        ]);
    }

    public function send(Request $request, AccountRecoveryService $recovery, OtpService $otp)
    {
        $user = $request->user();

        if (! $this->choosing($user) || ! $user->mobile || ! ($deletion = $recovery->detect($user))) {
            return $this->leave($user);
        }

        // A log mailer delivers nothing and the log copy hides the code, so a code made here could
        // never be used. None is made, and the page says so.
        if (Platform::mailMode() === 'safe_preview') {
            return redirect()->route('account.previous.code')->with('error', self::SAFE_PREVIEW_REFUSED);
        }

        $result = $otp->issueRecoveryEmail($user->mobile, $request->ip(), fn (string $code) => $this->emailCode($deletion->email, $code));

        // The code proves this one mailbox, so it may only open a request for this account.
        $request->session()->put(self::CODE_FOR, $deletion->id);

        AuditLog::record('account.recovery_code_requested', $user, ['result' => $result, 'sent_to' => $recovery->matchedByEmail($user, $deletion) ? 'own_address' : 'previous_account_address'], $user->id);

        return redirect()->route('account.previous.code')->with($result === OtpService::ISSUED
            ? ['status' => self::ACCEPTED_STATUS]
            : ['error' => $result === OtpService::SEND_FAILED ? 'The code could not be sent. Please try again later.' : OtpService::userMessage($result)]);
    }

    public const SAFE_PREVIEW_REFUSED = "We couldn't send your verification code. Please try again later.";

    /** Accepted for delivery is all the app can know. Arrival is proved only when the code is used. */
    public const ACCEPTED_STATUS = 'The mail service accepted your 6-digit code. Delivery can take a minute, so check Spam too. Enter the code below.';

    /** Session key: the archived account whose address the last code was sent to. */
    private const CODE_FOR = 'recovery.code_for_deletion';

    /**
     * Sent at once, as a queued job would hold the code in the jobs table. True only when the mail
     * service accepted the message: a message stopped on the way, or only written to a log, is not sent.
     */
    private function emailCode(string $address, string $code): bool
    {
        if (! DeliveryLog::allowed('mail', $address)) {
            return false;
        }

        try {
            // Laravel sends a clone, so the id is set here, where its delivery record can be found again.
            $notification = new AccountRecoveryCodeNotification($code);
            $notification->id = (string) Str::uuid();
            Notification::route('mail', $address)->notifyNow($notification);

            $status = Platform::schemaReady()
                ? MessageDelivery::where('notification_id', $notification->id)->value('status')
                : null;

            return $status === null || $status === MessageDelivery::ACCEPTED;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function confirm(Request $request, AccountRecoveryService $recovery, OtpService $otp)
    {
        $user = $request->user();

        if (! $this->choosing($user) || ! $user->mobile) {
            return $this->leave($user);
        }

        $request->validate(['otp' => 'required|digits:6']);

        $result = $otp->verifyRecoveryCode($user->mobile, $request->input('otp'));

        if ($result !== OtpService::OK) {
            AuditLog::record('auth.otp_failed', $user, ['flow' => 'account_recovery', 'reason' => $result], $user->id);

            return back()->withErrors(['otp' => $result === OtpService::BLOCKED
                ? 'Too many incorrect attempts. Please request a new code later.'
                : 'The code is invalid or has expired. Please request a new one.']);
        }

        $otp->clear($user->mobile, OtpService::PURPOSE_RECOVERY);
        $codeFor = $request->session()->pull(self::CODE_FOR);

        if (! $codeFor || ! $recovery->submit($user, (int) $codeFor)) {
            return redirect()->route('account.previous')->with('error', 'This request could not be started. It may already be open. Please try again later.');
        }

        return redirect()->route('account.recovery.status')->with('status', 'Your recovery request is pending Admin approval.');
    }

    public function status(Request $request)
    {
        $user = $request->user();

        if (! $this->waiting($user)) {
            return $this->leave($user);
        }

        return view('auth.recovery-status', [
            'recovery' => AccountRecoveryRequest::where('requester_id', $user->id)->whereNotNull('open_key')->latest('id')->first(),
            'local' => $this->local(),
        ]);
    }

    public function cancel(Request $request, AccountRecoveryService $recovery)
    {
        $user = $request->user();

        if (! $this->waiting($user)) {
            return $this->leave($user);
        }

        $recovery->cancel($user);

        return redirect()->route($this->homeFor($user))->with('status', 'Your recovery request was cancelled. You can continue with your new account.');
    }

    /** The role's own landing page. A Notary awaiting approval gets the waiting page. */
    private function homeFor(User $user): string
    {
        return $user->homeRoute();
    }
}
