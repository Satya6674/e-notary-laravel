<?php

namespace App\Console\Commands;

use App\Mail\TestMail;
use App\Services\DeliveryLog;
use App\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends exactly ONE synchronous test email to an address typed on the command
 * line. CLI only: there is deliberately no web route for this.
 *
 * It reports the real outcome: ACCEPTED (exit 0) only when the transport returned a sent message
 * outside Safe Preview; SIMULATED (exit 2) in Safe Preview; BLOCKED (exit 1) when the Live Mail
 * rules or the allowlist stopped it; FAILED (exit 1) on a transport error. Under Live Mail this
 * test message is never a permitted type, so it is always blocked there.
 */
class MailTest extends Command
{
    protected $signature = 'enotary:mail-test {to : Recipient email address} {--force : Skip the confirmation prompt}';

    protected $description = 'Send a single test email through the configured mailer (synchronous, bypasses the queue)';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not a valid email address.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $this->line("Mailer: {$mailer}  Host: ".config('mail.mailers.smtp.host').'  From: '.config('mail.from.address'));

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER is "log": the message will only be written to storage/logs/laravel.log.');
        }

        if (! $this->option('force') && ! $this->confirm("Send one test email to {$to}?", false)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        // A failover mailer may fall back to the log after a real failure, and the result would look
        // the same as a real acceptance. It cannot be reported truthfully, so it is not attempted.
        if (config("mail.mailers.{$mailer}.transport") === 'failover') {
            $this->error('NOT ATTEMPTED: the failover mailer can fall back to the log, so a real acceptance could not be told apart. Use the smtp mailer.');

            return self::INVALID;
        }

        try {
            $sent = Mail::to($to)->send(new TestMail());
        } catch (Throwable $e) {
            // Never echo the raw exception: transport errors can include the
            // AUTH exchange. Report the class and a sanitised message only.
            $this->error('FAILED: '.get_class($e));
            $this->error($this->sanitise($e->getMessage()));

            return self::FAILURE;
        }

        // Laravel returns no SentMessage when a MessageSending listener stopped the message: the
        // Live Mail rules (this test message is never a live type) or the recipient allowlist.
        if ($sent === null) {
            $reason = Platform::liveMailRefusal(null) ?? (DeliveryLog::allowed('mail', $to) ? 'stopped before sending' : 'recipient_not_allowlisted');
            $this->error("BLOCKED: not sent ({$reason}). Nothing left this computer.");

            return self::FAILURE;
        }

        if (Platform::mailMode() === 'safe_preview') {
            $this->warn('SIMULATED: Safe Preview wrote the message to the log only. Nothing was sent.');

            return self::INVALID;
        }

        $this->info("ACCEPTED by the mail transport (".config("mail.mailers.{$mailer}.transport").") for {$to}.");
        $this->line('Acceptance is not delivery: check the Inbox, then Spam/Promotions. Gmail may take up to a minute.');

        return self::SUCCESS;
    }

    private function sanitise(string $message): string
    {
        foreach ([config('mail.mailers.smtp.password'), config('mail.mailers.smtp.username')] as $secret) {
            if (filled($secret)) {
                $message = str_replace((string) $secret, '[redacted]', $message);
                $message = str_replace(base64_encode((string) $secret), '[redacted]', $message);
            }
        }

        return preg_replace('/AUTH \S+ \S+/', 'AUTH [redacted]', $message) ?? $message;
    }
}
