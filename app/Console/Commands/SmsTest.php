<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

/**
 * Sends exactly ONE OTP SMS to a number you own, via the configured MSG91
 * account. CLI only, no web route, no database rows. Each run costs one SMS.
 */
class SmsTest extends Command
{
    protected $signature = 'enotary:sms-test {mobile : 10-digit Indian mobile number you own} {--force : Skip the confirmation prompt}';

    protected $description = 'Send a single OTP test SMS through MSG91 (paid, CLI only)';

    public function handle(SmsService $sms): int
    {
        $mobile = SmsService::normalizeMobile($this->argument('mobile'));

        if (! $mobile) {
            $this->error('Enter a valid 10-digit Indian mobile number.');

            return self::FAILURE;
        }

        if (! SmsService::isConfigured()) {
            $this->error('MSG91 is not configured (MSG91_AUTH_KEY / MSG91_TEMPLATE_ID empty).');

            return self::FAILURE;
        }

        $masked = SmsService::mask($mobile);

        if (! $this->option('force') && ! $this->confirm("Send ONE paid OTP SMS to {$masked}?", false)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $otp = (string) random_int(100000, 999999);
        $ok = $sms->send($mobile, $otp);

        if (! $ok) {
            $this->error('MSG91 did not accept the request: '.($sms->lastError ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info("MSG91 accepted the request for {$masked}.");
        $this->line('MSG91 request_id: '.($sms->lastRequestId ?? '(none returned)'));
        $this->line('This confirms provider acceptance only. Delivery to the handset is NOT confirmed by this response.');
        $this->line('Verify in the MSG91 dashboard: OTP > Logs (search by the request_id above), or ask the phone owner whether a 6-digit code arrived. The code is intentionally not printed here.');
        $this->line('If it does not arrive within 2 minutes: check DLT template approval, sender ID, IP whitelist, and balance in the MSG91 dashboard.');

        return self::SUCCESS;
    }
}
