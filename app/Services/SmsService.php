<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one-time passwords through the official MSG91 OTP API.
 *
 * Credentials come from config/services.php (MSG91_* in .env). The OTP value,
 * the full mobile number and the auth key are never written to the log.
 *
 * send() returns true only when MSG91 both answered 2xx AND reported
 * "type": "success" in the JSON body. MSG91 can return HTTP 200 with
 * "type": "error" (bad template, no balance, DLT problem), so HTTP status
 * alone must not be trusted. "Accepted by MSG91" still does not prove the
 * handset received the message.
 */
class SmsService
{
    public const ENDPOINT = 'https://control.msg91.com/api/v5/otp';

    public const BALANCE_ENDPOINT = 'https://control.msg91.com/api/balance.php';

    public const ACCOUNT_CACHE = 'msg91:account-status';

    public const BUDGET_CACHE = 'msg91:budget-used:';

    /** Outcome of the last send() call, for status commands and logs. */
    public ?string $lastError = null;

    /**
     * MSG91's request identifier from the last accepted send() call, or null.
     * MSG91 answers a successful OTP request with {"request_id": "...", "type": "success"};
     * this id is what OTP > Logs and MSG91 support use to trace the request.
     * It is NOT a delivery confirmation.
     */
    public ?string $lastRequestId = null;

    /**
     * Reduce any typed Indian mobile number ("+91 98765 43210", "09876543210",
     * "919876543210") to its 10 significant digits. Returns null if it is not
     * a plausible 10-digit number.
     */
    public static function normalizeMobile(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $input);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /** The real MSG91 address, unless a local stand-in was named outside production. */
    public static function endpoint(): string
    {
        $override = (string) config('services.msg91.endpoint');

        return $override !== '' && parse_url($override, PHP_URL_HOST) === '127.0.0.1' && ! app()->isProduction()
            ? $override
            : self::ENDPOINT;
    }

    /**
     * A real send needs a recipient allowlist and a send budget. A whitelisted IP lets every
     * copy of this app on that connection spend money, so a copy with neither must not send.
     * A local stand-in costs nothing. Only the test suite may turn this off.
     */
    public static function limitsInPlace(): bool
    {
        if (self::endpoint() !== self::ENDPOINT) {
            return true;
        }

        if (app()->runningUnitTests() && ! config('services.msg91.enforce_limits')) {
            return true;
        }

        $allowlist = array_filter(array_map('trim', explode(',', (string) config('services.outbound.sms'))));

        return $allowlist !== [] && (int) config('services.msg91.send_budget') > 0;
    }

    /**
     * True only when real SMS may replace the local simulation. Filled-in settings are not
     * enough: the owner must declare the DLT work done, both DLT IDs must be present, the
     * send limits must be set, and MSG91 must accept this account right now.
     */
    public static function realSmsReady(): bool
    {
        return config('services.msg91.production_ready') === true
            && self::isConfigured()
            && filled(config('services.msg91.dlt_pe_id'))
            && filled(config('services.msg91.dlt_template_id'))
            && self::endpoint() === self::ENDPOINT
            && self::limitsInPlace()
            && app(self::class)->accountStatus() === 'ok';
    }

    /** True when both the auth key and the OTP template ID are configured. */
    public static function isConfigured(): bool
    {
        return filled(config('services.msg91.auth_key')) && filled(config('services.msg91.template_id'));
    }

    /**
     * Send a one-time password to a 10-digit Indian mobile number.
     */
    public function send(string $mobile, string $otp): bool
    {
        $this->lastError = null;
        $this->lastRequestId = null; // never let an earlier attempt's id leak into this one
        $mobile = self::normalizeMobile($mobile) ?? $mobile;
        $masked = self::mask($mobile);

        if (! self::isConfigured()) {
            $this->lastError = 'not_configured';
            Log::error('MSG91 OTP not sent: MSG91_AUTH_KEY or MSG91_TEMPLATE_ID is empty', ['mobile' => $masked]);

            return false;
        }

        if (! self::limitsInPlace()) {
            $this->lastError = 'blocked_no_allowlist_or_budget';
            Log::warning('SMS not attempted: real sending needs a recipient allowlist and a send budget', ['mobile' => $masked]);

            return false;
        }

        if (! DeliveryLog::allowed('sms', $mobile)) {
            $this->lastError = 'blocked_by_allowlist';
            Log::warning('SMS stopped by the outbound allowlist', ['mobile' => $masked]);

            return false;
        }

        if (config('services.msg91.preflight') && in_array($state = $this->accountStatus(), ['ip_blocked', 'invalid_key'], true)) {
            $this->lastError = 'preflight:'.$state;
            Log::warning('SMS not attempted: the MSG91 account check failed', ['mobile' => $masked, 'state' => $state]);

            return false;
        }

        if (! $this->takeFromBudget()) {
            $this->lastError = 'send_budget_spent';
            Log::warning('SMS not attempted: the approved send budget is spent', ['mobile' => $masked]);

            return false;
        }

        try {
            // One attempt only: a retry after a timeout could send a second paid SMS.
            $response = Http::timeout(10)
                // Force IPv4 so the request always comes from the IPv4 address
                // whitelisted on the MSG91 authkey (Windows otherwise prefers
                // IPv6, which MSG91 rejected with error 418 "IP is not whitelisted").
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders([
                    'authkey' => (string) config('services.msg91.auth_key'),
                    'Content-Type' => 'application/json',
                ])->post(self::endpoint(), [
                    'template_id' => config('services.msg91.template_id'),
                    'mobile' => '91'.$mobile,
                    'otp' => $otp,
                ]);
        } catch (Throwable $e) {
            $this->lastError = 'transport:'.get_class($e);
            Log::warning('MSG91 OTP request failed', ['mobile' => $masked, 'error' => get_class($e)]);

            return false;
        }

        $type = (string) $response->json('type', '');
        $ok = $response->successful() && strtolower($type) === 'success';

        if (! $ok) {
            // MSG91's "message" field is a safe, non-secret diagnostic
            // (e.g. "invalid template id", "insufficient balance").
            $this->lastError = 'provider:'.($response->json('message') ?? ('http '.$response->status()));
            Log::warning('MSG91 OTP rejected', [
                'mobile'  => $masked,
                'status'  => $response->status(),
                'type'    => $type,
                'message' => mb_substr((string) $response->json('message', ''), 0, 120),
            ]);

            return false;
        }

        $this->lastRequestId = self::extractRequestId($response->json('request_id'));

        // "Accepted" = MSG91 acknowledged the API call. It does not mean an OTP
        // record was created, billed, or delivered to the handset.
        Log::info('MSG91 OTP accepted', [
            'mobile'     => $masked,
            'status'     => $response->status(),
            'request_id' => $this->lastRequestId,
        ]);

        return true;
    }

    /**
     * Counts a paid attempt against the approved budget before it is made. A timeout still
     * counts, as MSG91 may have sent that message. Restarting the app does not refill it.
     */
    private function takeFromBudget(): bool
    {
        $budget = config('services.msg91.send_budget');

        if (blank($budget)) {
            return true;
        }

        $key = self::BUDGET_CACHE.config('services.msg91.budget_id');
        Cache::add($key, 0, now()->addDays(30));

        return Cache::increment($key) <= (int) $budget;
    }

    /**
     * Reads the account balance, which sends nothing and costs nothing. Returns ok,
     * ip_blocked, invalid_key, no_permission, unknown, unreachable or not_configured. An
     * IP or key refusal stops paid sends. no_permission does not, as it may cover only this
     * balance call, and MSG91 itself would refuse an unpermitted send. A definite refusal stops
     * paid sends. The balance call is the only one that reveals an IP refusal up front.
     */
    public function accountStatus(): string
    {
        if (! self::isConfigured()) {
            return 'not_configured';
        }

        return Cache::remember(self::ACCOUNT_CACHE, now()->addMinutes(5), fn () => $this->accountReport()['state']);
    }

    /**
     * One uncached account check. Free, and sends nothing.
     *
     * @return array{state: string, balance: ?string} balance is MSG91's OTP route figure
     */
    public function accountReport(): array
    {
        try {
            $body = Http::timeout(10)->withOptions(['force_ip_resolve' => 'v4'])
                ->get(self::BALANCE_ENDPOINT, ['authkey' => (string) config('services.msg91.auth_key'), 'type' => 106])
                ->json();
        } catch (Throwable) {
            return ['state' => 'unreachable', 'balance' => null];
        }

        // A working key gets its balance as a plain number. Only that reads as ok, so an
        // error code nobody listed here can never pass as a working account.
        $code = is_array($body) ? (string) ($body['msg'] ?? '') : '';

        $state = match (true) {
            $body === null => 'unreachable',
            $code === '418' => 'ip_blocked',
            in_array($code, ['201', '301'], true) => 'invalid_key',
            $code === '204' => 'no_permission',
            is_numeric($body) => 'ok',
            default => 'unknown',
        };

        return ['state' => $state, 'balance' => $state === 'ok' ? (string) $body : null];
    }

    /**
     * Accept only a plain identifier string (MSG91 uses 24 hex chars); anything
     * else (missing, non-string, empty, oversized, odd characters) becomes null.
     */
    public static function extractRequestId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value) === 1 ? $value : null;
    }

    public static function mask(string $mobile): string
    {
        return strlen($mobile) > 4 ? str_repeat('*', strlen($mobile) - 4).substr($mobile, -4) : '****';
    }
}
