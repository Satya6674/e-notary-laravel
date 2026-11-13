<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\VerificationStatus;
use Illuminate\Console\Command;

/**
 * Shows whether the MSG91 OTP integration is configured. Secrets are masked.
 */
class SmsStatus extends Command
{
    protected $signature = 'enotary:sms-status {--account : Also ask MSG91 for the account state and balance, which is free and sends nothing}';

    protected $description = 'Show the MSG91 OTP configuration (secrets masked); no network call unless --account is used';

    public function handle(): int
    {
        $key = (string) config('services.msg91.auth_key');
        $tpl = (string) config('services.msg91.template_id');
        $sender = (string) config('services.msg91.sender_id');

        $this->table(['Setting', 'Value'], [
            ['Endpoint', SmsService::ENDPOINT],
            ['MSG91_AUTH_KEY', $key === '' ? '(empty)' : '(set, '.strlen($key).' chars)'],
            ['MSG91_TEMPLATE_ID', $tpl === '' ? '(empty)' : '(set, '.strlen($tpl).' chars)'],
            ['MSG91_SENDER_ID', $sender === '' ? '(empty)' : $sender],
            ['Mobile verification mode', VerificationStatus::mode()],
            ['Real sending', SmsService::limitsInPlace() ? 'Limits in place' : 'LOCKED: no SMS allowlist or send budget, so nothing is sent'],
            ['OTP policy', '6 digits, bcrypt-hashed, '.OtpService::ttl().' min expiry, 5 attempts then 15 min block'],
            ['Per-number limits', '1 SMS / 60 s, max 5 / hour'],
            ['IP limits', 'request 5/min, verify 10/min, reset 5/min'],
            ['Mandatory for citizens', User::mobileVerificationSupported()
                ? 'ENFORCED (users.mobile_verified_at exists)'
                : 'NOT enforced: users.mobile_verified_at missing, run migration 2026_10_29_000001'],
        ]);

        if (! SmsService::isConfigured()) {
            $this->warn('! MSG91 is NOT configured: set MSG91_AUTH_KEY and MSG91_TEMPLATE_ID in .env, then run php artisan config:clear');
        } else {
            $this->info('MSG91 credentials are present. Template approval (DLT) and account balance can only be checked in the MSG91 dashboard.');
        }

        if ($this->option('account') && SmsService::isConfigured()) {
            $report = app(SmsService::class)->accountReport();

            $this->line('Account state: '.$report['state'].', OTP route balance: '.($report['balance'] ?? 'not available'));

            if ($report['state'] !== 'ok') {
                $this->warn('! Do not send a paid test while the account state is not ok. See docs/communication/SMS_OTP_ACCEPTANCE_REPORT.md');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
