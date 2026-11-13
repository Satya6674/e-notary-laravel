<?php

namespace App\Console\Commands;

use App\Support\Platform;
use App\Support\TrustedProxySettings;
use Illuminate\Console\Command;

/**
 * Pre-flight for a real deployment. Reads configuration only (no secret is printed) and fails on
 * anything unsafe for real users. Run on the server after setting .env and before opening the site:
 *
 *   php artisan enotary:deploy-check
 *
 * Exit code 1 while any FAIL remains. Locally it is expected to fail (APP_ENV=local, http, loopback).
 */
class DeployCheck extends Command
{
    protected $signature = 'enotary:deploy-check';

    protected $description = 'Check that the configuration is safe for a production deployment (read-only)';

    public function handle(): int
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $rows = [];
        $check = function (string $item, bool $ok, string $need, string $severity = 'FAIL') use (&$rows) {
            $rows[] = [$item, $ok ? 'PASS' : $severity, $ok ? '' : $need];
        };

        $check('APP_ENV=production', app()->isProduction(), 'Set APP_ENV=production.');
        $check('APP_DEBUG=false', config('app.debug') === false, 'Set APP_DEBUG=false (debug pages leak code and data).');
        $check('APP_KEY set', filled(config('app.key')), 'Generate a new APP_KEY for this server (never reuse the development key).');
        $check('APP_URL is https', str_starts_with($url, 'https://'), 'Set APP_URL to the https:// address of the real domain.');
        $check('APP_URL is a public host', $host !== '' && ! Platform::privateHost($host), 'APP_URL must be the real domain, not localhost, a .test name or a private IP.');
        // Reverse proxies (H-2). An HTTPS site must say how TLS reaches it: through a proxy or load
        // balancer (TRUSTED_PROXIES=<its address or CIDR>), or directly (TRUSTED_PROXIES=none). Left
        // empty behind a proxy, every visitor shares the proxy's IP (rate limits, audit IPs) and HTTPS
        // is not recognised. The entries are only reported here, never changed.
        // null (empty), [] (none) or the list string; an array is joined only so it can be inspected.
        $configured = config('trustedproxy.proxies');
        $proxies = is_array($configured) ? implode(',', $configured) : (string) $configured;
        $directTls = config('trustedproxy.direct_tls') === true;
        $seen = TrustedProxySettings::inspect($proxies);
        $list = fn (string $kind) => implode(', ', $seen[$kind]);
        $check('Trusted proxies declared (list or none)', ! str_starts_with($url, 'https://') || $directTls || $seen['entries'] !== [], 'Declare how TLS reaches the app: TRUSTED_PROXIES=<the proxy\'s address or CIDR> behind a reverse proxy or load balancer, or TRUSTED_PROXIES=none when this server terminates TLS itself.', 'WARN');
        $check('Trusted proxies not wildcard-equivalent', $seen['wildcard'] === [], 'TRUSTED_PROXIES contains '.$list('wildcard').': this trusts any directly connecting client (safe only if the app is reachable solely through the proxy). Use the proxy\'s address or CIDR range.', 'WARN');
        $check('Trusted proxies not broad keywords', $seen['broad'] === [], 'TRUSTED_PROXIES contains '.$list('broad').': every private-network address is trusted. Prefer the load balancer\'s own subnet.', 'WARN');
        $check('Trusted proxies not a template placeholder', $seen['placeholder'] === [], 'TRUSTED_PROXIES still holds the template placeholder '.$list('placeholder').': replace it with the proxy\'s address, or none.', 'WARN');
        $check('Trusted proxies entries valid', $seen['invalid'] === [], 'TRUSTED_PROXIES has entries that are not IP addresses or CIDR ranges ('.$list('invalid').'). They trust nothing, so the proxy behind them is not recognised.', 'WARN');
        $check('Secure session cookie', config('session.secure') === true, 'Set SESSION_SECURE_COOKIE=true (automatic when APP_URL is https).');
        $check('Session cookie HttpOnly', config('session.http_only') === true, 'Keep SESSION_HTTP_ONLY=true.');
        $check('Session encryption', config('session.encrypt') === true, 'Set SESSION_ENCRYPT=true.', 'WARN');
        $check('Session lifetime <= 120 min', (int) config('session.lifetime') <= 120, 'Set SESSION_LIFETIME to 120 or less.', 'WARN');
        $check('Protected Admin account configured', config('accounts.protected', []) !== [], 'Set PROTECTED_ACCOUNTS to the permanent Admin\'s email address so no other Admin can edit, deactivate or delete it.', 'WARN');
        $check('Protected accounts entries valid', config('accounts.protected_invalid', []) === [], 'PROTECTED_ACCOUNTS has '.count(config('accounts.protected_invalid', [])).' entry(ies) that are not valid email addresses; they protect nothing.', 'WARN');
        $check('Admin two-factor required', config('accounts.admin_mfa_required') === true, 'Set ADMIN_MFA_REQUIRED=true (after the migration and the Admin\'s enrollment).');
        // Required but not installed: the site fails closed (Admin pages answer 503), so say why here.
        $check('Admin two-factor table present', ! \App\Services\AdminMfa::required() || $this->mfaTablePresent(), 'Table admin_mfa_factors is missing: run migration 2026_11_10_000031 (Admin pages stay closed until it exists).');
        $check('Queue is not sync', config('queue.default') !== 'sync', 'Use QUEUE_CONNECTION=database (or redis) with a supervised worker.');
        $check('SMS disabled', blank(config('services.msg91.auth_key')), 'Set MSG91_ENABLED=false unless SMS (DLT) has been approved.');
        $check('Log level not debug', config('logging.channels.'.config('logging.default').'.level', config('logging.level')) !== 'debug' && env('LOG_LEVEL') !== 'debug', 'Set LOG_LEVEL=warning (or info).', 'WARN');
        $check('Daily log files', in_array('daily', (array) config('logging.channels.stack.channels'), true) || config('logging.default') === 'daily', 'Set LOG_STACK=daily and LOG_DAILY_DAYS (retention).', 'WARN');
        $check('Mail mode', ! in_array(Platform::mailMode(), ['misconfigured'], true), 'Mail is misconfigured: '.(Platform::liveMailProblem() ?? 'see enotary:mail-test').'.');
        $check('Mail from address', filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false, 'Set MAIL_FROM_ADDRESS.');
        $check('Not on a development database', ! in_array((string) config('database.connections.'.config('database.default').'.database'), ['enotary_phpunit', 'enotary_scratch'], true), 'DB_DATABASE points at a test database.');
        $check('DB user is not root', strtolower((string) config('database.connections.'.config('database.default').'.username')) !== 'root', 'Use the restricted application account, never root.');
        $check('Config cached', app()->configurationIsCached(), 'Run php artisan config:cache (and route:cache, view:cache) after every deploy.', 'WARN');
        $check('Email stop is off', ! Platform::mailStopped(), 'The email emergency stop is on (enotary:mail-stop off).', 'WARN');

        $this->table(['Item', 'Status', 'Needed'], $rows);

        $fails = count(array_filter($rows, fn ($r) => $r[1] === 'FAIL'));
        $warns = count(array_filter($rows, fn ($r) => $r[1] === 'WARN'));
        $this->line("{$fails} FAIL, {$warns} WARN.");

        return $fails ? self::FAILURE : self::SUCCESS;
    }

    /** A fresh check (not the cached one), false when the database cannot answer. */
    private function mfaTablePresent(): bool
    {
        try {
            \App\Services\AdminMfa::flushAvailability();

            return \App\Services\AdminMfa::available();
        } catch (\Throwable) {
            return false;
        }
    }
}
