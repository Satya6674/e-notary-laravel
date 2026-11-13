<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\AccountRecoveryService;
use App\Services\DeliveryLog;
use App\Services\MobileOtpEmailBridge;
use App\Services\OtpService;
use App\Support\Platform;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * A user account. The "role" column decides what the user can do:
 * "public" (normal user), "notary" or "admin".
 *
 * Verification states are independent of each other:
 *   email_verified_at   - the user clicked the signed verification link, or, in local
 *                         development only, used the emailed code. See
 *                         email_verification_method.
 *   mobile_verified_at  - the user completed an SMS OTP for their number
 *                         (column added by the 2026_10_29 migration; code
 *                         tolerates its absence until that migration runs).
 *   notary_approved_at  - an admin approved this notary account (notary only).
 *
 * A local simulation lives in mobile_verification_status, never in mobile_verified_at, and
 * counts only while the local bridge is open. See App\Support\VerificationStatus.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    // Columns that can be filled with User::create([...]).
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'role',
        'email_verified_at',
        'mobile_verified_at',
        'notary_approved_at',
    ];

    // Columns that are never included when the user is converted to JSON/array.
    protected $hidden = [
        'password',
        'retired_passwords',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'  => 'datetime',
        'mobile_verified_at' => 'datetime',
        'notary_approved_at' => 'datetime',
        'deactivated_at'     => 'datetime',
        'deleted_at'         => 'datetime',
        'mobile_simulated_at' => 'datetime',
        'pending_mobile_requested_at' => 'datetime',
        'password_reset_required_at' => 'datetime',
        'retired_passwords' => 'array',
        'was_admin_at' => 'datetime',
        'mobile_fallback_verified_at' => 'datetime',
        'mobile_manual_verified_at' => 'datetime',
    ];

    public const METHOD_DEV_EMAIL = 'development_email_otp';

    /** The 6-digit code from the registration email, typed in by the account holder. */
    public const METHOD_EMAIL_CODE = 'email_code';

    /** The mobile step done with a code sent to the verified email, while SMS is unavailable. */
    public const METHOD_EMAIL_FALLBACK = 'email_fallback';

    /** Mobile step completed through the email fallback. Never means "proven by SMS". */
    public const STATUS_FALLBACK_COMPLETED = 'fallback_completed';

    /** users.mobile_sms_status: no text message was sent for this number. */
    public const SMS_NOT_SENT = 'not_sent';

    /** users.mobile_sms_status: an SMS code reached the phone and was typed in. */
    public const SMS_DELIVERED = 'delivered';

    public const METHOD_SMS = 'msg91_sms';

    /**
     * An Admin confirmed the address or number by other means (Admin > User Verification Management).
     * Never an OTP and never SMS: a manual mobile check leaves mobile_verified_at unset.
     */
    public const METHOD_ADMIN_MANUAL = 'admin_manual';

    /** users.mobile_verification_status after an Admin manual check of the number. */
    public const STATUS_ADMIN_VERIFIED = 'admin_verified';

    public const METHOD_MOCK_SMS = 'mock_sms';

    public const STATUS_SIMULATED = 'simulated';

    public const STATUS_SMS_VERIFIED = 'sms_verified';

    /** Set at registration, so accounts made before this feature are never checked. */
    public const CHOICE_CHECK = 'check';

    /** Checked, and no previous account applies. */
    public const CHOICE_NONE = 'none';

    /** A previous account was found, and the person must choose. */
    public const CHOICE_PENDING = 'pending';

    /** A recovery request is open, so this account stays closed. */
    public const CHOICE_RECOVERY = 'recovery';

    /** Decided: this person continues with this account. */
    public const CHOICE_NEW = 'new';

    /** Minutes a requested number waits for its SMS code before the password is needed again. */
    public const PENDING_MOBILE_MINUTES = 30;

    /** Cached once per process: does the users table have mobile_verified_at yet? */
    private static ?bool $mobileVerificationSupported = null;

    /** Cached once per process: does the users table have the verification method columns yet? */
    private static ?bool $verificationStatusSupported = null;

    private static ?bool $mobileFallbackSupported = null;

    private static ?bool $mobileManualSupported = null;

    /** The active temporary access grant, looked up once per instance. False until looked up. */
    private TemporaryAccessGrant|null|false $activeGrant = false;

    /** Cached once per process: does the users table have deleted_at yet? */
    private static ?bool $softDeleteSupported = null;

    /** Cached once per process: has the account recovery migration run? */
    private static ?bool $accountRecoverySupported = null;

    /**
     * Has migration 000023 added the permanent former-Admin marker? Not cached: it is read only
     * when an identity may be released or a role changes, and must be right after a rollback.
     */
    public static function adminMarkerSupported(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('users', 'was_admin_at');
    }

    /** A proof belongs to one address or number, so changing either ends what it proved. */
    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if (! self::verificationStatusSupported()) {
                return;
            }

            if ($user->isDirty('email') && ! $user->isDirty('email_verification_method')) {
                $user->email_verification_method = null;
            }

            if ($user->isDirty('mobile') && ! $user->isDirty('mobile_verification_status')) {
                $user->forceFill(['mobile_verification_method' => null, 'mobile_verification_status' => null, 'mobile_simulated_at' => null]);

                // An email fallback covered the old number only. Its history row stays in verification_events.
                if (self::mobileFallbackSupported()) {
                    $user->forceFill(['mobile_sms_status' => null, 'mobile_fallback_verified_at' => null, 'mobile_fallback_number' => null]);
                }

                // So did an Admin manual check. Its history row stays in verification_events too.
                if (self::mobileManualSupported()) {
                    $user->forceFill(['mobile_manual_verified_at' => null, 'mobile_manual_number' => null]);
                }
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isNotary(): bool
    {
        return $this->role === 'notary';
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null || $this->isDeleted();
    }

    /**
     * Deleted by an admin. Deliberately not Laravel's SoftDeletes: its global scope would
     * hide this person from their own applications, documents and queued emails.
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public static function softDeleteSupported(): bool
    {
        return self::$softDeleteSupported ??= Schema::hasColumn('users', 'deleted_at');
    }

    /** Deleting needs this migration too, as the deletion record holds the released identity. */
    public static function accountRecoverySupported(): bool
    {
        return self::$accountRecoverySupported ??= Schema::hasTable('account_recovery_requests');
    }

    /** A recovered account has no usable password until its owner sets one by email link. */
    public function mustResetPassword(): bool
    {
        return $this->password_reset_required_at !== null;
    }

    /** True for a password this account had before any recovery. None of them may return. */
    public function isRetiredPassword(string $password): bool
    {
        foreach ((array) $this->retired_passwords as $hash) {
            if (Hash::check($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** The real address for an administrator to read, as a deleted account holds a placeholder. */
    public function contactEmail(): string
    {
        return $this->archivedIdentity()?->email ?? $this->email;
    }

    public function contactMobile(): ?string
    {
        return $this->archivedIdentity()?->mobile ?? $this->mobile;
    }

    private function archivedIdentity(): ?AccountDeletion
    {
        return $this->isDeleted() ? AccountDeletion::where('user_id', $this->id)->whereNull('restored_at')->latest('id')->first() : null;
    }

    /**
     * A deleted account's address may now belong to someone else, so ordinary mail stops. Only a
     * notice about the deletion or recovery goes out, to the address stored on its own record.
     */
    public function routeNotificationForMail($notification = null): ?string
    {
        if (! $this->isDeleted()) {
            return $this->email;
        }

        return $notification && method_exists($notification, 'recipientAddress') ? $notification->recipientAddress() : null;
    }

    /** Accounts that are not deleted. Safe before the migration has run. */
    public function scopeNotDeleted($query)
    {
        return $query->when(self::softDeleteSupported(), fn ($q) => $q->whereNull('deleted_at'));
    }

    /**
     * Notary accounts whose latest Admin decision was a rejection (or a withdrawn approval) and
     * that are not approved now. There is no status column: the audit trail is the record.
     */
    public function scopeRejectedNotaries($query)
    {
        $decisions = ['admin.notary_approved', 'admin.notary_unapproved'];

        return $query->where('role', 'notary')->whereNull('notary_approved_at')->whereIn('id', fn ($latest) => $latest
            ->select('a.model_id')->from('audit_logs as a')
            ->where('a.model_type', 'User')->where('a.action', 'admin.notary_unapproved')
            ->whereRaw('a.id = (select max(b.id) from audit_logs as b where b.model_type = ? and b.model_id = a.model_id and b.action in (?, ?))', ['User', ...$decisions]));
    }

    /** The latest Admin approval decision on this Notary account, or null if none was made. */
    public function notaryDecision(): ?AuditLog
    {
        return AuditLog::where('model_type', 'User')->where('model_id', $this->id)
            ->whereIn('action', ['admin.notary_approved', 'admin.notary_unapproved'])
            ->latest('id')->first();
    }

    /** The owner's permanent account (config/accounts.php): only it may change itself; nobody may delete or deactivate it. */
    public function isProtectedAccount(): bool
    {
        return in_array(strtolower((string) $this->email), config('accounts.protected', []), true);
    }

    public function isRejectedNotary(): bool
    {
        return $this->isNotary() && $this->notary_approved_at === null
            && $this->notaryDecision()?->action === 'admin.notary_unapproved';
    }

    /**
     * A notary who has been approved by an admin and may act on requests. A deactivated or
     * deleted account keeps its approval date, but may not be given or do any work.
     */
    public function isApprovedNotary(): bool
    {
        return $this->isNotary() && $this->notary_approved_at !== null && ! $this->isDeactivated();
    }

    /**
     * Work that still waits on this Notary: applications assigned to them that are not yet
     * finished, and appointments still open. Nothing moves on its own when they stop working.
     *
     * @return array{applications: int, appointments: int}
     */
    public function openNotaryWork(): array
    {
        return [
            'applications' => $this->assignedRequests()->whereIn('status', self::OPEN_NOTARY_STATUSES)->count(),
            'appointments' => $this->notaryAppointments()->whereIn('status', ['requested', 'confirmed'])->count(),
        ];
    }

    /** Application states that still need the assigned Notary. */
    public const OPEN_NOTARY_STATUSES = [NotaryRequest::STATUS_ASSIGNED, NotaryRequest::STATUS_CORRECTION, NotaryRequest::STATUS_APPROVED];

    /** Appointments this Notary is responsible for. */
    public function notaryAppointments()
    {
        return $this->hasMany(Appointment::class, 'notary_id');
    }

    /** An address confirmed only by the local bridge counts only while that bridge is open. */
    public function hasVerifiedEmail(): bool
    {
        return parent::hasVerifiedEmail()
            && ($this->email_verification_method !== self::METHOD_DEV_EMAIL || MobileOtpEmailBridge::enabled());
    }

    /** True when the local bridge recorded a simulation, and it is still open. Never genuine. */
    public function mobileSimulationAccepted(): bool
    {
        return self::verificationStatusSupported()
            && $this->mobile_verification_status === self::STATUS_SIMULATED
            && $this->mobile_verification_method === self::METHOD_DEV_EMAIL
            && MobileOtpEmailBridge::enabled();
    }

    /**
     * True when the mobile step was completed with a code sent to the verified email, for the number
     * on the account now. It opens the citizen area, but it is not SMS proof: mobile_verified_at stays
     * unset, so SMS password reset and phone proof in account recovery still need a real SMS.
     */
    public function mobileFallbackVerified(): bool
    {
        return self::mobileFallbackSupported()
            && $this->mobile_verification_method === self::METHOD_EMAIL_FALLBACK
            && $this->mobile_verification_status === self::STATUS_FALLBACK_COMPLETED
            && $this->mobile_fallback_verified_at !== null
            && filled($this->mobile)
            && $this->mobile_fallback_number === $this->mobile;
    }

    /**
     * True when an Admin confirmed the number on the account now by other means. It opens the citizen
     * area like the email fallback does, but it is not SMS proof: mobile_verified_at stays unset, so
     * SMS password reset and phone proof in account recovery still need a real SMS.
     */
    public function mobileManualVerified(): bool
    {
        return self::mobileManualSupported()
            && $this->mobile_verification_method === self::METHOD_ADMIN_MANUAL
            && $this->mobile_verification_status === self::STATUS_ADMIN_VERIFIED
            && $this->mobile_manual_verified_at !== null
            && filled($this->mobile)
            && $this->mobile_manual_number === $this->mobile;
    }

    /** True when an Admin confirmed the address by other means. The address counts as verified. */
    public function emailManuallyVerified(): bool
    {
        return $this->hasVerifiedEmail() && $this->email_verification_method === self::METHOD_ADMIN_MANUAL;
    }

    public static function mobileManualSupported(): bool
    {
        return self::$mobileManualSupported ??= self::verificationStatusSupported()
            && Schema::hasColumn('users', 'mobile_manual_verified_at');
    }

    /** The time-limited login exception in force now, or null. It never verifies anything. */
    public function activeTemporaryAccess(): ?TemporaryAccessGrant
    {
        if ($this->activeGrant === false) {
            $this->activeGrant = $this->exists && ! $this->isAdmin() && TemporaryAccessGrant::supported()
                ? TemporaryAccessGrant::where('user_id', $this->id)->active()->latest('id')->first()
                : null;
        }

        // Compared with the clock on every call, so a grant read earlier in a request still ends on time.
        return $this->activeGrant?->isActive() ? $this->activeGrant : null;
    }

    public function forgetTemporaryAccess(): void
    {
        $this->activeGrant = false;
    }

    /**
     * True while an active grant is what lets this account past a verification step it has not done.
     * Once every step is done, the grant changes nothing and no restriction applies.
     */
    public function usingTemporaryAccess(): bool
    {
        return ! $this->isAdmin() && ! $this->isDeactivated() && ! $this->mustResetPassword()
            && $this->nextVerificationRoute() !== null && $this->activeTemporaryAccess() !== null;
    }

    /** The verification page this account must be sent to now: none while temporary access is in force. */
    public function verificationGateRoute(): ?string
    {
        return $this->usingTemporaryAccess() ? null : $this->nextVerificationRoute();
    }

    public static function mobileFallbackSupported(): bool
    {
        return self::$mobileFallbackSupported ??= self::verificationStatusSupported()
            && Schema::hasColumn('users', 'mobile_fallback_verified_at');
    }

    /**
     * True when there is nothing left to do on the mobile page. An email fallback is done only while
     * the fallback is the mobile mode: once SMS is available, the page lets the person add SMS proof,
     * which is recorded alongside the fallback in verification_events and never replaces it there.
     */
    public function mobileStepDone(): bool
    {
        return $this->hasVerifiedEmail() && ($this->hasVerifiedMobile() || $this->mobileSimulationAccepted() || $this->mobileManualVerified()
            || ($this->mobileFallbackVerified() && \App\Support\VerificationStatus::mode() === \App\Support\VerificationStatus::MODE_EMAIL_FALLBACK));
    }

    /** Columns for a code that really arrived by SMS, or by the local stand-in. */
    public static function smsVerifiedAttributes(): array
    {
        if (! self::verificationStatusSupported()) {
            return [];
        }

        $mock = Platform::smsMode() === 'mock';

        return [
            'mobile_verification_method' => $mock ? self::METHOD_MOCK_SMS : self::METHOD_SMS,
            'mobile_verification_status' => $mock ? self::STATUS_SIMULATED : self::STATUS_SMS_VERIFIED,
            'mobile_simulated_at' => null,
        ] + (self::mobileFallbackSupported() && ! $mock ? ['mobile_sms_status' => self::SMS_DELIVERED] : []);
    }

    /** True once a real SMS OTP was completed for this user's mobile number. */
    public function hasVerifiedMobile(): bool
    {
        return self::mobileVerificationSupported() && $this->mobile_verified_at !== null;
    }

    public static function verificationStatusSupported(): bool
    {
        return self::$verificationStatusSupported ??= Schema::hasColumn('users', 'mobile_verification_status');
    }

    public static function mobileVerificationSupported(): bool
    {
        return self::$mobileVerificationSupported ??= Schema::hasColumn('users', 'mobile_verified_at');
    }

    /**
     * Citizens must prove control of their mobile number (SMS OTP) before using
     * the citizen area. False for admin/notary accounts, and for everyone until
     * the mobile_verified_at column exists, so the live app is never locked out
     * by a pending migration.
     */
    public function needsMobileVerification(): bool
    {
        return $this->role === 'public' && config('accounts.citizen_mobile_verification') === true && self::mobileVerificationSupported() && ! $this->hasVerifiedMobile() && ! $this->mobileSimulationAccepted() && ! $this->mobileFallbackVerified() && ! $this->mobileManualVerified();
    }

    /**
     * Whether an SMS code may reset this account's password. The number must be one
     * this person has proven, so a mistyped or claimed number opens nothing. Admins are
     * email-only: they have no verification step, and SMS is the weakest channel for
     * the most powerful account.
     */
    public function canRecoverByMobile(): bool
    {
        return ! $this->isAdmin() && ! $this->isDeactivated() && ! $this->mustResetPassword() && $this->hasVerifiedMobile();
    }

    /** The number waiting for its SMS code, or null if none, or if the request is too old. */
    public function pendingMobile(): ?string
    {
        if (! $this->pending_mobile || ! $this->pending_mobile_requested_at) {
            return null;
        }

        return $this->pending_mobile_requested_at->gt(now()->subMinutes(self::PENDING_MOBILE_MINUTES)) ? $this->pending_mobile : null;
    }

    /** Route name of the sign-up verification step still to complete, or null when done. */
    public function nextVerificationRoute(): ?string
    {
        // The admin area does not require a verified email (docs/VERIFICATION_POLICY.md),
        // so a mail outage must not park the admin on the verification page.
        if ($this->isAdmin()) {
            return null;
        }

        if (! $this->hasVerifiedEmail()) {
            return 'verification.notice';
        }

        if ($this->needsMobileVerification()) {
            return 'verification.mobile.notice';
        }

        return null;
    }

    /**
     * Route of the step about a previous account, or null. It waits for verification, so nothing
     * is hinted at before identity checks, and an account never registered here is not looked at.
     */
    public function accountChoiceRoute(): ?string
    {
        if (! self::accountRecoverySupported() || $this->isAdmin() || $this->nextVerificationRoute()) {
            return null;
        }

        return match (app(AccountRecoveryService::class)->resolveChoice($this)) {
            self::CHOICE_PENDING => 'account.previous',
            self::CHOICE_RECOVERY => 'account.recovery.status',
            default => null,
        };
    }

    /** Dashboard route for the role, once every verification step is done. */
    public function homeRoute(): string
    {
        return match ($this->role) {
            'admin'  => 'admin.dashboard',
            // A Notary awaiting approval has a page of their own, never a refusal.
            'notary' => $this->isApprovedNotary() ? 'notary.dashboard' : 'notary.pending',
            default  => 'dashboard',
        };
    }

    /** Where to send the user right now: the pending verification step, else their dashboard. */
    public function landingUrl(): string
    {
        return route($this->verificationGateRoute() ?? $this->accountChoiceRoute() ?? $this->homeRoute());
    }

    /** Requests this notary has been assigned. */
    public function assignedRequests()
    {
        return $this->hasMany(NotaryRequest::class, 'notary_id');
    }

    /** Requests submitted by this user. */
    public function requests()
    {
        return $this->hasMany(NotaryRequest::class, 'user_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        // Locally, the one emailed code on the mobile page confirms the address instead.
        if (MobileOtpEmailBridge::enabled() && ! $this->isAdmin()) {
            return;
        }

        $this->sendEmailVerificationCode();
    }

    /**
     * Issues a new 6-digit code for this address (stored hashed, 10 minutes, one use) and sends the
     * email that carries it with the signed link. Returns OtpService::ISSUED, or the refusal
     * (cooldown, hourly limit, lock-out), in which case nothing is sent.
     */
    public function sendEmailVerificationCode(?string $ip = null): string
    {
        return $this->sendEmailVerification($ip)['result'];
    }

    /**
     * As sendEmailVerificationCode(), and also says what happened to the email: accepted by the mail
     * service, simulated (Safe Preview), blocked (allowlist or Live Mail rules), failed, or null when
     * nothing was recorded. The email is sent at once, not queued, so the answer is known before the
     * page says anything, and no job ever holds the code. A code whose email was blocked or failed is
     * cancelled, as nobody received it.
     *
     * @return array{result: string, delivery: ?string}
     */
    public function sendEmailVerification(?string $ip = null): array
    {
        $otp = app(OtpService::class);
        [$result, $code] = $otp->issueEmailCode($this, $ip ?? request()?->ip());

        if ($result !== OtpService::ISSUED) {
            return ['result' => $result, 'delivery' => null];
        }

        $delivery = app(DeliveryLog::class)->sendNow($this, new VerifyEmailNotification($code, now()->addMinutes(OtpService::ttl())));

        if (in_array($delivery, [MessageDelivery::BLOCKED, MessageDelivery::FAILED], true)) {
            $otp->cancelEmailCode($this);
        }

        return ['result' => $result, 'delivery' => $delivery];
    }

    public function sendPasswordResetNotification($token): void
    {
        if ($this->isDeleted()) {
            return;
        }

        $this->notify(new ResetPasswordNotification($token));
    }
}
