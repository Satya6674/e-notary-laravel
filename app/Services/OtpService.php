<?php

namespace App\Services;

use App\Models\MessageDelivery;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Single implementation of the mobile OTP lifecycle, shared by every flow that proves a
 * mobile number: sign-up verification, password reset, account recovery and number change.
 *
 * Storage: otp_verifications (mobile, otp, expires_at, verified_at, attempts, blocked_until,
 * ip_address, created_at). The otp column holds "<flow>|<bcrypt hash>", never the code itself.
 * The flow tag keeps the flows apart without a schema change; the hash also includes a
 * flow-specific prefix, so a row whose tag was altered still cannot pass another flow.
 *
 * Kept apart for each flow:
 *   - which code is current: a new code expires older codes of the same flow only
 *   - attempts and the MAX_ATTEMPTS lock-out, which a new code of that flow cannot skip
 *   - the RESEND_SECONDS cooldown and the MAX_PER_HOUR limit
 *   - expiry, single use, freshness and clearing after success
 *
 * Shared by every flow, because each one costs a paid SMS:
 *   - per number MAX_PER_DAY, per IP MAX_PER_IP_DAY, whole site services.msg91.daily_limit
 *
 * Issuing is atomic: a per-number Cache lock covers the checks and the insert, and the daily
 * counters are incremented first and given back when over the limit, so two requests at the
 * same moment cannot both pass a limit.
 *
 * Rows written before flow tags existed (no "|") keep their old behaviour until they expire,
 * which takes TTL_MINUTES: every flow that could use them before still sees them.
 *
 * SMS delivery goes through SmsService; "issued" means the provider accepted the request,
 * not that the handset received it.
 */
class OtpService
{
    public const TTL_MINUTES             = 10;
    public const MAX_ATTEMPTS            = 5;
    public const BLOCK_MINUTES           = 15;
    public const RESEND_SECONDS          = 60;
    public const MAX_PER_HOUR            = 5;
    public const MAX_PER_DAY             = 10;
    public const MAX_PER_IP_DAY          = 30;
    public const VERIFIED_WINDOW_MINUTES = 10;

    public const FLOW_MOBILE_VERIFICATION = 'mobile_verification';

    public const FLOW_PASSWORD_RESET = 'password_reset';

    public const FLOW_MOBILE_CHANGE = 'mobile_change';

    public const PURPOSE_RECOVERY = 'account_recovery';

    /**
     * The 6-digit code in the registration email. Its rows are keyed "email:<user id>" in the mobile
     * column, and its hash also covers the address it went to, so a code proves that one mailbox only:
     * after an email change, a code sent to the old address no longer matches. No SMS is involved, so
     * it does not count against the SMS daily limits; the per-account cooldown and hourly limit apply.
     */
    public const FLOW_EMAIL_VERIFICATION = 'email_verification';

    /**
     * The mobile step while SMS is unavailable: a code for the mobile purpose, sent to the account's
     * verified email address. Its rows are keyed by the mobile number, like every mobile flow, and its
     * hash also covers the address it went to. It is a purpose of its own: its expiry, attempts,
     * lock-out, cooldown and hourly limit are separate from the email verification code, and no SMS
     * is sent, so the SMS daily limits do not apply.
     */
    public const FLOW_MOBILE_EMAIL_FALLBACK = 'mobile_email_fallback';

    /**
     * Forgot Password: a code sent to the account's registered, verified email address, whether the
     * person typed that address or the account's mobile number. Rows are keyed "email:<user id>" like
     * the registration code, and the hash also covers the address, so a code proves that one mailbox.
     * Its expiry, attempts, lock-out, cooldown and hourly limit are its own. No SMS is involved.
     */
    public const FLOW_PASSWORD_RESET_EMAIL = 'password_reset_email';

    /** Flows whose codes go by email: no SMS daily limits, and never matched against untagged legacy rows. */
    private const EMAIL_FLOWS = [self::FLOW_EMAIL_VERIFICATION, self::FLOW_MOBILE_EMAIL_FALLBACK, self::FLOW_PASSWORD_RESET_EMAIL];

    /** Each flow and the prefix hashed in with its codes. */
    private const FLOW_HASH_PREFIXES = [
        self::FLOW_MOBILE_VERIFICATION => '',
        self::FLOW_PASSWORD_RESET => 'password-reset:',
        self::FLOW_MOBILE_CHANGE => 'mobile-change:',
        self::PURPOSE_RECOVERY => 'account-recovery:',
        self::FLOW_EMAIL_VERIFICATION => 'email-verification:',
        self::FLOW_MOBILE_EMAIL_FALLBACK => 'mobile-email-fallback:',
        self::FLOW_PASSWORD_RESET_EMAIL => 'password-reset-email:',
    ];

    /** Hashed in with a bridge code, so the real SMS path can never accept one. */
    private const BRIDGE_HASH_PREFIX = 'email-bridge:';

    /** Separates the flow tag from the hash. A bcrypt hash never contains it. */
    private const TAG_SEPARATOR = '|';

    /** Seconds a request waits for another request on the same number to finish issuing. */
    private const LOCK_WAIT_SECONDS = 5;

    /** What to tell a signed-in person when issue() did not send a code. */
    public static function userMessage(string $result): string
    {
        return match ($result) {
            self::COOLDOWN     => 'A code was sent less than a minute ago and is still valid. Please wait '.self::RESEND_SECONDS.' seconds before requesting another.',
            self::HOURLY_LIMIT => 'You have requested the maximum of '.self::MAX_PER_HOUR.' codes this hour. Please try again later.',
            self::DAILY_LIMIT  => 'The daily limit for codes has been reached. Please try again tomorrow.',
            self::BLOCKED_NOW  => 'Too many incorrect attempts. Please wait 15 minutes before requesting a new code.',
            default            => 'SMS is unavailable right now, so no code was sent. Please try again later.',
        };
    }

    /** Minutes a code stays valid, from configuration. */
    public static function ttl(): int
    {
        // Clamped to 1-15 minutes: a misconfigured value must not leave codes valid for hours.
        return max(1, min(15, (int) config('services.msg91.otp_ttl', self::TTL_MINUTES)));
    }

    public const ISSUED        = 'issued';
    public const COOLDOWN      = 'cooldown';
    public const HOURLY_LIMIT  = 'hourly_limit';
    public const DAILY_LIMIT   = 'daily_limit';
    public const BLOCKED_NOW   = 'blocked_now';
    public const SEND_FAILED   = 'send_failed';

    public const OK      = 'ok';
    public const EXPIRED = 'expired';
    public const BLOCKED = 'blocked';
    public const INVALID = 'invalid';

    public function __construct(private SmsService $sms, private DeliveryLog $deliveries)
    {
    }

    /**
     * Generate, store (hashed) and send a new code for a 10-digit mobile, for one flow.
     * Returns ISSUED, COOLDOWN, HOURLY_LIMIT, DAILY_LIMIT, BLOCKED_NOW or SEND_FAILED.
     */
    public function issue(string $mobile, ?string $ip = null, string $purpose = self::FLOW_MOBILE_VERIFICATION, ?int $userId = null): string
    {
        $this->assertFlow($purpose);
        $this->assertSmsFlow($purpose);
        $masked = SmsService::mask($mobile);

        // Local testing only, and only for this one flow. Everything else stays on SMS.
        $bridge = $purpose === self::FLOW_MOBILE_VERIFICATION ? MobileOtpEmailBridge::forUser($userId) : null;

        $reserved = $this->reserve($mobile, $ip, $purpose, $bridge !== null);

        if (is_string($reserved)) {
            return $reserved;
        }

        [$id, $code] = $reserved;

        if (! ($bridge ? $bridge->send($code) : $this->sms->send($mobile, $code))) {
            $reason = $bridge ? $bridge->lastError : $this->sms->lastError;

            // Operators see the reason; callers keep their generic user message.
            Log::warning('OTP SMS could not be sent', ['mobile' => $masked, 'reason' => $reason]);
            $this->deliveries->sms($purpose, $masked, str_contains((string) $reason, 'allowlist') ? MessageDelivery::BLOCKED : MessageDelivery::FAILED, null, mb_substr((string) $reason, 0, 200), $userId);

            // Nobody received this code, so it must not stay usable. Other flows are untouched.
            $this->expire($id);

            return self::SEND_FAILED;
        }

        if ($bridge) {
            $this->deliveries->sms($purpose, $masked, MessageDelivery::SIMULATED, null, 'Development email bridge: no SMS sent', $userId);

            return self::ISSUED;
        }

        Log::info('OTP SMS accepted by provider', ['mobile' => $masked, 'request_id' => $this->sms->lastRequestId]);
        $this->deliveries->sms($purpose, $masked, MessageDelivery::ACCEPTED, $this->sms->lastRequestId, null, $userId);

        return self::ISSUED;
    }

    /**
     * A recovery code sent by email, under the same limits as any other code. It is never filled
     * in on the page. $deliver returns false when nothing was sent.
     */
    public function issueRecoveryEmail(string $mobile, ?string $ip, callable $deliver): string
    {
        $reserved = $this->reserve($mobile, $ip, self::PURPOSE_RECOVERY, false);

        if (is_string($reserved)) {
            return $reserved;
        }

        [$id, $code] = $reserved;

        if (! $deliver($code)) {
            // Nobody received this code, so it must not stay usable.
            $this->expire($id);

            return self::SEND_FAILED;
        }

        return self::ISSUED;
    }

    /** The key an account's email codes are stored under, within the 15-character mobile column. */
    public static function emailKey(User $user): string
    {
        $key = 'email:'.$user->getKey();

        if (strlen($key) > 15) {
            throw new InvalidArgumentException('Account id too long for an email code key.');
        }

        return $key;
    }

    /** Binds a code to one address, in any letter case. */
    private static function emailBinding(User $user): string
    {
        return sha1(strtolower(trim((string) $user->email))).':';
    }

    /**
     * A new registration email code for this account and its current address, under the cooldown,
     * hourly limit and lock-out of this flow. Returns [ISSUED, code] or [refusal, null]. The caller
     * sends it; the code is never stored or logged in the clear.
     *
     * @return array{0: string, 1: ?string}
     */
    public function issueEmailCode(User $user, ?string $ip = null): array
    {
        $reserved = $this->reserve(self::emailKey($user), $ip, self::FLOW_EMAIL_VERIFICATION, false, self::emailBinding($user));

        return is_string($reserved) ? [$reserved, null] : [self::ISSUED, $reserved[1]];
    }

    /**
     * Ends this account's newest unused email code, for a message that was not delivered, so a code
     * nobody received cannot be used. Its row stays, so the cooldown and hourly limit still count it.
     */
    public function cancelEmailCode(User $user, string $flow = self::FLOW_EMAIL_VERIFICATION): void
    {
        $this->rows(self::emailKey($user), $flow)
            ->whereNull('verified_at')->orderByDesc('id')->limit(1)->update(['expires_at' => now()]);
    }

    /**
     * A new Forgot Password code for this account and its current address, under this flow's cooldown,
     * hourly limit and lock-out. Returns [ISSUED, code] or [refusal, null]. The caller emails it.
     *
     * @return array{0: string, 1: ?string}
     */
    public function issuePasswordResetCode(User $user, ?string $ip = null): array
    {
        $reserved = $this->reserve(self::emailKey($user), $ip, self::FLOW_PASSWORD_RESET_EMAIL, false, self::emailBinding($user));

        return is_string($reserved) ? [$reserved, null] : [self::ISSUED, $reserved[1]];
    }

    /** Checks a Forgot Password code against the newest one for this account and its current address. */
    public function verifyPasswordResetCode(User $user, string $code): string
    {
        return $this->check(self::emailKey($user), $code, self::FLOW_PASSWORD_RESET_EMAIL, false, self::emailBinding($user));
    }

    /** True when this account passed a Forgot Password code within the freshness window. */
    public function hasRecentPasswordReset(User $user): bool
    {
        return $this->hasRecentVerification(self::emailKey($user), self::FLOW_PASSWORD_RESET_EMAIL);
    }

    /** The cost of a code check, for a request that has no account behind it: see check(). */
    public function spendTimingCheck(string $code): void
    {
        Hash::check($code, $this->timingHash());
    }

    /** Checks a registration email code against the newest one for this account and its current address. */
    public function verifyEmailCode(User $user, string $code): string
    {
        return $this->check(self::emailKey($user), $code, self::FLOW_EMAIL_VERIFICATION, false, self::emailBinding($user));
    }

    /**
     * A mobile-purpose code to send to this account's verified email, for its current number.
     * Returns [ISSUED, code] or [refusal, null]. The caller sends it.
     *
     * @return array{0: string, 1: ?string}
     */
    public function issueMobileFallbackCode(User $user, ?string $ip = null): array
    {
        $reserved = $this->reserve((string) $user->mobile, $ip, self::FLOW_MOBILE_EMAIL_FALLBACK, false, self::emailBinding($user));

        return is_string($reserved) ? [$reserved, null] : [self::ISSUED, $reserved[1]];
    }

    /** Checks a mobile fallback code for this account's current number and email address. */
    public function verifyMobileFallbackCode(User $user, string $code): string
    {
        return $this->check((string) $user->mobile, $code, self::FLOW_MOBILE_EMAIL_FALLBACK, false, self::emailBinding($user));
    }

    /**
     * Checks every limit and stores the new code, all under one lock for the number, so a second
     * request at the same moment sees this one's row. Returns [row id, code], or a refusal.
     * Sending happens after the lock is released, so a slow provider holds nobody up.
     *
     * @return array{0: int, 1: string}|string
     */
    private function reserve(string $mobile, ?string $ip, string $flow, bool $bridge, string $binding = ''): array|string
    {
        try {
            return Cache::lock('otp-issue:'.$mobile, 15)->block(self::LOCK_WAIT_SECONDS, function () use ($mobile, $ip, $flow, $bridge, $binding) {
                if ($refused = $this->refusal($mobile, $ip, $flow)) {
                    return $refused;
                }

                $code = (string) random_int(100000, 999999);

                return [$this->store($mobile, $ip, $flow, $bridge ? self::BRIDGE_HASH_PREFIX : self::FLOW_HASH_PREFIXES[$flow].$binding, $code), $code];
            });
        } catch (LockTimeoutException) {
            // Another request for this number is issuing a code right now.
            return self::COOLDOWN;
        }
    }

    /** Why no code may go out now, or null. Passing counts against the daily limits. */
    private function refusal(string $mobile, ?string $ip, string $flow): ?string
    {
        $masked = SmsService::mask($mobile);

        // A lock-out must hold: requesting a new code would otherwise reset the attempts.
        if ($this->rows($mobile, $flow)->where('blocked_until', '>', now())->exists()) {
            return self::BLOCKED_NOW;
        }

        $recent = $this->rows($mobile, $flow)
            ->where('created_at', '>=', now()->subHour())
            ->orderByDesc('id')
            ->get(['created_at']);

        if ($recent->count() >= self::MAX_PER_HOUR) {
            Log::warning('OTP SMS hourly limit reached', ['mobile' => $masked]);

            return self::HOURLY_LIMIT;
        }

        $last = $recent->first();
        if ($last && Carbon::parse($last->created_at)->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return self::COOLDOWN;
        }

        // The daily ceilings exist because each SMS is paid. An email code sends no SMS.
        if (! in_array($flow, self::EMAIL_FLOWS, true) && ! $this->takeDailyAllowance($mobile, $ip)) {
            Log::warning('OTP SMS daily limit reached', ['mobile' => $masked]);

            return self::DAILY_LIMIT;
        }

        // Email codes have daily ceilings of their own: per account and flow, per IP and for the whole
        // site. They stop one person flooding a mailbox and stop a script using up the mail account's
        // daily sending quota, which would leave every real user without codes for the rest of the day.
        if (in_array($flow, self::EMAIL_FLOWS, true) && ! $this->takeEmailAllowance($mobile, $ip, $flow)) {
            Log::warning('OTP email daily limit reached', ['key' => $masked, 'flow' => $flow]);

            return self::DAILY_LIMIT;
        }

        return null;
    }

    /** Email codes a flow may send to one account per day (config accounts.email_code_*). */
    public static function emailDailyLimits(): array
    {
        return [
            'site' => max(1, (int) config('accounts.email_code_daily_site', 300)),
            'key' => max(1, (int) config('accounts.email_code_daily_per_account', self::MAX_PER_DAY)),
            'ip' => max(1, (int) config('accounts.email_code_daily_per_ip', self::MAX_PER_IP_DAY)),
        ];
    }

    /** Email codes sent today across the site, against its ceiling. */
    public function emailCodesSentToday(): int
    {
        return (int) Cache::get('otp-mail-day:'.now()->format('Ymd').':site', 0);
    }

    /** As takeDailyAllowance(), for email codes, with separate counters. */
    private function takeEmailAllowance(string $key, ?string $ip, string $flow): bool
    {
        $day = now()->format('Ymd');
        $max = self::emailDailyLimits();

        $limits = [
            "otp-mail-day:{$day}:site" => $max['site'],
            "otp-mail-day:{$day}:key:{$flow}:{$key}" => $max['key'],
        ];

        if ($ip) {
            $limits["otp-mail-day:{$day}:ip:{$ip}"] = $max['ip'];
        }

        $taken = [];

        foreach ($limits as $counter => $limit) {
            Cache::add($counter, 0, now()->addDays(2));
            $taken[] = $counter;

            if ((int) Cache::increment($counter) > $limit) {
                foreach ($taken as $back) {
                    Cache::decrement($back);
                }

                return false;
            }
        }

        return true;
    }

    private function store(string $mobile, ?string $ip, string $flow, string $prefix, string $code): int
    {
        // Keep recent rows for the per-number limits; drop stale ones.
        DB::table('otp_verifications')->where('mobile', $mobile)->where('created_at', '<', now()->subDay())->delete();

        // Wrong guesses carry over to the new code: asking for another code must not reset the count,
        // or an attacker could guess 4 times per code indefinitely. A count is dropped only after a
        // code was used, after a lock-out was served, or when the last code is more than an hour old.
        $previous = $this->rows($mobile, $flow)->orderByDesc('id')->first(['attempts', 'verified_at', 'blocked_until', 'created_at']);
        $carried = $previous && ! $previous->verified_at && ! $previous->blocked_until
            && Carbon::parse($previous->created_at)->gt(now()->subHour())
            ? min((int) $previous->attempts, self::MAX_ATTEMPTS - 1)
            : 0;

        // Only the newest code of this flow may work. Codes of other flows are untouched.
        $this->rows($mobile, $flow)->whereNull('verified_at')->update(['expires_at' => now()]);

        return (int) DB::table('otp_verifications')->insertGetId([
            'mobile'     => $mobile,
            // Never the code itself: the flow tag, then a hash of the prefixed code.
            'otp'        => $flow.self::TAG_SEPARATOR.Hash::make($prefix.$code),
            'expires_at' => now()->addMinutes(self::ttl()),
            'attempts'   => $carried,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    private function expire(int $id): void
    {
        DB::table('otp_verifications')->where('id', $id)->whereNull('verified_at')->update(['expires_at' => now()]);
    }

    /**
     * Counts this send against the number, the IP and the whole site. Each counter is raised
     * first and given back if that went over, which is atomic for any number of requests at once.
     * Counters live in the cache, not the OTP table, because a completed verification deletes
     * its rows.
     */
    private function takeDailyAllowance(string $mobile, ?string $ip): bool
    {
        $day = now()->format('Ymd');

        $limits = [
            "otp-day:{$day}:site"          => (int) config('services.msg91.daily_limit', 300),
            "otp-day:{$day}:num:{$mobile}" => self::MAX_PER_DAY,
        ];

        if ($ip) {
            $limits["otp-day:{$day}:ip:{$ip}"] = self::MAX_PER_IP_DAY;
        }

        $taken = [];

        foreach ($limits as $key => $limit) {
            Cache::add($key, 0, now()->addDays(2));
            $taken[] = $key;

            if ((int) Cache::increment($key) > $limit) {
                foreach ($taken as $back) {
                    Cache::decrement($back);
                }

                return false;
            }
        }

        return true;
    }

    /** SMS accepted for sending today across the site, against the daily ceiling. */
    public function sentToday(): int
    {
        return (int) Cache::get('otp-day:'.now()->format('Ymd').':site', 0);
    }

    /**
     * Check a submitted code against the newest code of this flow.
     * Returns OK (and marks the record consumed), EXPIRED, BLOCKED or INVALID.
     */
    public function verify(string $mobile, string $code, bool $fromBridge = false, string $flow = self::FLOW_MOBILE_VERIFICATION): string
    {
        $this->assertFlow($flow);
        $this->assertSmsFlow($flow);

        return $this->check($mobile, $code, $flow, $fromBridge);
    }

    /** As verify(), for a code issued for account recovery. No other code passes here. */
    public function verifyRecoveryCode(string $mobile, string $code): string
    {
        return $this->check($mobile, $code, self::PURPOSE_RECOVERY, false);
    }

    private function check(string $mobile, string $code, string $flow, bool $fromBridge, string $binding = ''): string
    {
        // Fetched on every path, so no path is shorter for the lack of it.
        $standIn = $this->timingHash();

        // The row lock makes simultaneous guesses count one by one, and stops one code
        // being accepted twice.
        return DB::transaction(function () use ($mobile, $code, $flow, $fromBridge, $standIn, $binding) {
            $record = $this->rows($mobile, $flow)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Every refusal costs one bcrypt comparison, like a wrong code. Without it, a number
            // with no account (no code at all) would answer measurably faster than one with a code.
            if (! $record || $record->verified_at || now()->greaterThan($record->expires_at)) {
                Hash::check($code, $standIn);

                return self::EXPIRED;
            }

            if ($record->blocked_until && now()->lessThan($record->blocked_until)) {
                Hash::check($code, $standIn);

                return self::BLOCKED;
            }

            if (! $this->matches($record->otp, $code, $flow, $fromBridge, $binding)) {
                $attempts = $record->attempts + 1;

                DB::table('otp_verifications')->where('id', $record->id)->update([
                    'attempts'      => $attempts,
                    'blocked_until' => $attempts >= self::MAX_ATTEMPTS ? now()->addMinutes(self::BLOCK_MINUTES) : null,
                ]);

                return self::INVALID;
            }

            DB::table('otp_verifications')->where('id', $record->id)->update(['verified_at' => now()]);

            return self::OK;
        });
    }

    /**
     * A bcrypt hash of a random value, at the current cost, compared against when there is no
     * code to check. Kept in the cache so it is made once, not on every request.
     */
    private function timingHash(): string
    {
        $rounds = (int) config('hashing.bcrypt.rounds', 12);

        return Cache::rememberForever('otp:timing-hash:'.$rounds, fn () => Hash::make(bin2hex(random_bytes(16))));
    }

    /**
     * A tagged row must carry this flow's tag, and its hash this flow's prefix. An untagged row
     * from before flow tags is checked exactly as it was then: recovery with the recovery prefix,
     * a bridge code with the bridge prefix, any other flow with none.
     */
    private function matches(string $stored, string $code, string $flow, bool $fromBridge, string $binding = ''): bool
    {
        $tag = $flow.self::TAG_SEPARATOR;

        if (str_contains($stored, self::TAG_SEPARATOR)) {
            if (! str_starts_with($stored, $tag)) {
                return false;
            }

            $prefix = $fromBridge && $flow === self::FLOW_MOBILE_VERIFICATION ? self::BRIDGE_HASH_PREFIX : self::FLOW_HASH_PREFIXES[$flow].$binding;

            return Hash::check($prefix.$code, substr($stored, strlen($tag)));
        }

        // Rows from before flow tags were SMS codes. A code sent by email is always tagged.
        if (in_array($flow, self::EMAIL_FLOWS, true)) {
            return false;
        }

        $prefix = match (true) {
            $flow === self::PURPOSE_RECOVERY => self::FLOW_HASH_PREFIXES[self::PURPOSE_RECOVERY],
            $fromBridge => self::BRIDGE_HASH_PREFIX,
            default => '',
        };

        return Hash::check($prefix.$code, $stored);
    }

    /** Seconds until each limit of this flow lifts, for the page. The server still enforces every one. */
    public function status(string $mobile, string $flow = self::FLOW_MOBILE_VERIFICATION): array
    {
        $this->assertFlow($flow);

        $record = $this->rows($mobile, $flow)->orderByDesc('id')->first();

        $seconds = fn ($time) => $time ? max(0, now()->diffInSeconds(Carbon::parse($time), false)) : 0;

        $expires = $record && ! $record->verified_at ? (int) $seconds($record->expires_at) : 0;

        return [
            'active'   => $expires > 0,
            'expires'  => $expires,
            'cooldown' => $record ? (int) $seconds(Carbon::parse($record->created_at)->addSeconds(self::RESEND_SECONDS)) : 0,
            'blocked'  => $record ? (int) $seconds($record->blocked_until) : 0,
            'attempts_left' => $record ? max(0, self::MAX_ATTEMPTS - (int) $record->attempts) : self::MAX_ATTEMPTS,
        ];
    }

    /** True when the number completed this flow's verification within the freshness window. */
    public function hasRecentVerification(string $mobile, string $flow = self::FLOW_MOBILE_VERIFICATION): bool
    {
        $this->assertFlow($flow);

        return $this->rows($mobile, $flow)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subMinutes(self::VERIFIED_WINDOW_MINUTES))
            ->exists();
    }

    /**
     * Remove this flow's records for the number after its protected action succeeded, or every
     * record for the number when no flow is given (the number no longer belongs to the account).
     */
    public function clear(string $mobile, ?string $flow = null): void
    {
        if ($flow === null) {
            DB::table('otp_verifications')->where('mobile', $mobile)->delete();

            return;
        }

        $this->assertFlow($flow);
        $this->rows($mobile, $flow)->delete();
    }

    /** Last provider request id from the most recent issue(), if any. */
    public function lastRequestId(): ?string
    {
        return $this->sms->lastRequestId;
    }

    /** This flow's rows for the number, plus untagged rows from before flow tags. */
    private function rows(string $mobile, string $flow)
    {
        $tag = $flow.self::TAG_SEPARATOR;

        return DB::table('otp_verifications')
            ->where('mobile', $mobile)
            ->where(fn ($query) => $query
                // SUBSTR compares exactly; LIKE would treat the "_" in a flow name as a wildcard.
                ->where(DB::raw('SUBSTR(otp, 1, '.strlen($tag).')'), $tag)
                ->orWhere('otp', 'not like', '%'.self::TAG_SEPARATOR.'%'));
    }

    /** The SMS entry points take numbers. Email codes go through issueEmailCode() and verifyEmailCode(). */
    private function assertSmsFlow(string $flow): void
    {
        if (in_array($flow, self::EMAIL_FLOWS, true)) {
            throw new InvalidArgumentException('Codes sent by email are issued and checked with their own methods.');
        }
    }

    private function assertFlow(string $flow): void
    {
        if (! array_key_exists($flow, self::FLOW_HASH_PREFIXES)) {
            throw new InvalidArgumentException("Unknown OTP flow [{$flow}].");
        }
    }
}
