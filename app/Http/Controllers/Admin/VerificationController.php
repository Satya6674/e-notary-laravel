<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TemporaryAccessGrant;
use App\Models\User;
use App\Services\DeliveryLog;
use App\Services\ManualVerificationService;
use App\Services\SmsService;
use App\Services\VerificationLedger;
use App\Support\AdminReauth;
use App\Support\SensitiveInput;
use App\Support\VerificationStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Admin > User Verification Management. Citizens and Notaries (approved or awaiting approval) whose
 * email or mobile verification cannot be completed. Every write goes through
 * ManualVerificationService, and every one needs a reason, the way ownership was confirmed, a tick
 * box and the acting Admin's own password. Contacts are shown masked; no OTP, hash or secret is read.
 */
class VerificationController extends Controller
{
    public function __construct(private ManualVerificationService $service)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $role = $request->get('role', 'all');
        $state = $request->get('state', 'all');

        $query = User::query()->notDeleted()->whereIn('role', ['public', 'notary']);

        if ($search !== '') {
            $digits = SmsService::normalizeMobile($search);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->when($digits, fn ($q) => $q->orWhere('mobile', $digits))
                ->when(! $digits && ctype_digit($search), fn ($q) => $q->orWhere('mobile', 'like', "%{$search}%")));
        }

        match ($role) {
            'public' => $query->where('role', 'public'),
            'notary' => $query->where('role', 'notary')->whereNotNull('notary_approved_at'),
            'pending_notary' => $query->where('role', 'notary')->whereNull('notary_approved_at'),
            default => null,
        };

        if ($state === 'email_pending') {
            $query->whereNull('email_verified_at');
        } elseif ($state === 'mobile_pending' && User::mobileVerificationSupported()) {
            $query->where('role', 'public')->whereNull('mobile_verified_at');
        } elseif ($state === 'temporary_access' && TemporaryAccessGrant::supported()) {
            $query->whereIn('id', TemporaryAccessGrant::query()->active()->select('user_id'));
        }

        return view('admin.verification.index', [
            'users' => $query->orderByDesc('id')->paginate(20)->withQueryString(),
            'search' => $search,
            'role' => $role,
            'state' => $state,
            'supported' => ManualVerificationService::supported(),
        ]);
    }

    public function show(Request $request, User $user, VerificationLedger $ledger)
    {
        Gate::authorize('viewVerification', $user);

        $actor = $request->user();
        $refusal = $this->service->refusal($actor, $user);

        return view('admin.verification.show', [
            'user' => $user,
            'badges' => VerificationStatus::badges($user),
            'maskedEmail' => DeliveryLog::maskEmail($user->email),
            'maskedMobile' => filled($user->mobile) ? SmsService::mask($user->mobile) : null,
            'history' => $ledger->history($user),
            'historySupported' => VerificationLedger::supported(),
            'grants' => TemporaryAccessGrant::supported()
                ? TemporaryAccessGrant::with(['grantor:id,name', 'revoker:id,name'])->where('user_id', $user->id)->latest('id')->limit(20)->get()
                : collect(),
            'supported' => ManualVerificationService::supported(),
            'refusal' => $refusal,
            'emailRefusal' => $refusal ?? $this->service->verifyRefusal($user, ManualVerificationService::PURPOSE_EMAIL),
            'mobileRefusal' => $refusal ?? $this->service->verifyRefusal($user, ManualVerificationService::PURPOSE_MOBILE),
            'grantRefusal' => $refusal ?? $this->service->grantRefusal($user),
            'evidenceMethods' => ManualVerificationService::EVIDENCE_METHODS,
        ]);
    }

    public function verify(Request $request, User $user)
    {
        Gate::authorize('manageVerification', $user);

        $data = $request->validate($this->confirmationRules($request) + [
            'purpose' => ['required', Rule::in([ManualVerificationService::PURPOSE_EMAIL, ManualVerificationService::PURPOSE_MOBILE])],
            'reason' => 'required|string|min:20|max:500',
            'evidence_method' => ['required', Rule::in(array_keys(ManualVerificationService::EVIDENCE_METHODS))],
        ], $this->messages());

        try {
            $this->service->verify($request->user(), $user, $data['purpose'], trim($data['reason']), $data['evidence_method']);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage())->withInput($request->except(SensitiveInput::FIELDS));
        }

        return redirect()->route('admin.verification.show', $user)->with('success', $data['purpose'] === ManualVerificationService::PURPOSE_EMAIL
            ? 'The email address is now Verified Manually by Admin. Nothing was sent to the account holder.'
            : 'The mobile number is now Verified Manually by Admin. It is not SMS proof, and nothing was sent.');
    }

    public function grant(Request $request, User $user)
    {
        Gate::authorize('manageVerification', $user);

        $data = $request->validate($this->confirmationRules($request) + [
            'hours' => 'required|integer|min:'.TemporaryAccessGrant::MIN_HOURS.'|max:'.TemporaryAccessGrant::MAX_HOURS,
            'reason' => 'required|string|min:20|max:500',
            'evidence_method' => ['required', Rule::in(array_keys(ManualVerificationService::EVIDENCE_METHODS))],
        ], $this->messages());

        try {
            $grant = $this->service->grant($request->user(), $user, (int) $data['hours'], trim($data['reason']), $data['evidence_method']);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage())->withInput($request->except(SensitiveInput::FIELDS));
        }

        return redirect()->route('admin.verification.show', $user)->with('success', __('Temporary access is active until :time. Nothing was marked as verified.', [
            'time' => $grant->expires_at->ist('d M Y H:i'),
        ]));
    }

    public function revoke(Request $request, User $user, TemporaryAccessGrant $grant)
    {
        Gate::authorize('viewVerification', $user);

        $data = $request->validate($this->confirmationRules($request) + [
            'revoke_reason' => 'required|string|min:10|max:500',
        ], $this->messages());

        try {
            $this->service->revoke($request->user(), $user, $grant, trim($data['revoke_reason']));
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.verification.show', $user)->with('success', 'Temporary access was revoked.');
    }

    /**
     * The tick box and the acting Admin's own password, on every sensitive action, on top of the
     * two-factor step-up on the route. Wrong passwords are limited to 5 per 15 minutes and audited
     * (AdminReauth::checkPassword); while locked, even the right password is refused.
     */
    private function confirmationRules(Request $request): array
    {
        return [
            'confirm' => 'accepted',
            'admin_password' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                $result = AdminReauth::checkPassword($request, (string) $value);

                if ($result === 'locked') {
                    $fail('Too many incorrect passwords. Please wait 15 minutes. Nothing was changed.');
                } elseif ($result === 'invalid') {
                    $fail('Your password is not correct. Nothing was changed.');
                }
            }],
        ];
    }

    private function messages(): array
    {
        return [
            'confirm.accepted' => 'Please tick the box to confirm.',
            'admin_password.required' => 'Enter your own password to confirm.',
            'reason.min' => 'Give a reason of at least 20 characters.',
            'revoke_reason.min' => 'Give a reason of at least 10 characters.',
        ];
    }
}
