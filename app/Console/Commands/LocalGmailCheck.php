<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeliveryLog;
use App\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Dotenv\Dotenv;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * The gate scripts/Start-Local-Gmail.ps1 runs before starting anything. It reads what
 * this process really uses rather than trusting the environment, and prints no secrets.
 * --smtp logs in to Gmail and logs out again. It sends nothing.
 */
class LocalGmailCheck extends Command
{
    /** The disposable database Local Gmail runs on, never a permanent one. */
    public const SCRATCH_DATABASE = Platform::LOCAL_GMAIL_DATABASE;

    public const QUEUE = 'local_gmail';

    protected $signature = 'enotary:local-gmail-check {--smtp : Also log in to the mail server, sending nothing} {--json : Print one JSON object}';

    protected $description = 'Verify that Local Gmail mode is isolated and ready (secrets masked)';

    public function handle(): int
    {
        $failures = $this->failures();
        $smtp = $this->option('smtp') ? $this->smtpLogin() : 'not checked';

        if (! in_array($smtp, ['ok', 'not checked'], true)) {
            $failures[] = "Mail server login failed: {$smtp}";
        }

        $report = [
            'ok'         => $failures === [],
            'failures'   => $failures,
            'mode'       => Platform::mailMode(),
            'database'   => $this->database(),
            'app_url'    => config('app.url'),
            'smtp'       => $smtp,
            'worker'     => Cache::has(Platform::WORKER_HEARTBEAT),
            'waiting'    => $this->count('jobs', ['queue' => self::QUEUE]),
            'held'       => $this->count('jobs') - $this->count('jobs', ['queue' => self::QUEUE]),
            'failed'     => $this->count('failed_jobs'),
            'recipients' => $this->recipients(),
            'sms'        => blank(config('services.msg91.auth_key')) ? 'off' : 'on, approved number and budget only',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Check', 'Result'], [
            ['Mail mode', $report['mode']],
            ['Database', $report['database']],
            ['Links use', $report['app_url']],
            ['Mail server login', $report['smtp']],
            ['SMS', $report['sms']],
            ['Worker heartbeat', $report['worker'] ? 'yes' : 'no'],
            ['Waiting on '.self::QUEUE, $report['waiting']],
            ['Held on other queues, not touched', $report['held']],
            ['Failed jobs', $report['failed']],
        ]);
        $this->table(['Approved recipient', 'Account'], array_map(fn ($r) => [$r['address'], $r['account']], $report['recipients']));

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $failures === [] ? $this->info('Local Gmail is ready.') : $this->error('Local Gmail is NOT ready.');

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> Each one stops the startup. */
    private function failures(): array
    {
        $smtp = config('mail.mailers.smtp');
        $from = (string) config('mail.from.address');

        $checks = [
            'Environment must be local.' => app()->environment('local'),
            'The permanent .env must keep MAIL_MAILER=log, so Apache and a plain artisan serve never send real mail.' => $this->envFileMailer() === 'log',
            'MAIL_MODE must resolve to local_gmail, which a log mailer or production prevents.' => Platform::mailMode() === 'local_gmail',
            'Configuration is cached, so these settings are being ignored.' => ! app()->configurationIsCached(),
            'The database must be '.self::SCRATCH_DATABASE.', and is not.' => $this->database() === self::SCRATCH_DATABASE && Platform::onLocalGmailDatabase(),
            'The test database is missing portal migrations.' => $this->database() === self::SCRATCH_DATABASE && Platform::schemaReady(),
            'Storage must be outside the project.' => ! str_starts_with($this->path(storage_path()), $this->path(base_path())),
            'SMS must be off, or limited to one approved number with a send budget.' => blank(config('services.msg91.auth_key')) || $this->smsIsControlled(),
            'The mailer must be smtp.' => config('mail.default') === 'smtp',
            'The mail host must be smtp.gmail.com on port 587, which uses TLS.' => ($smtp['host'] ?? '') === 'smtp.gmail.com' && (int) ($smtp['port'] ?? 0) === 587,
            'The mail username or App Password is missing.' => filled($smtp['username'] ?? null) && filled($smtp['password'] ?? null),
            'MAIL_FROM_ADDRESS must equal MAIL_USERNAME, or Gmail rewrites it.' => $from !== '' && strcasecmp($from, (string) ($smtp['username'] ?? '')) === 0,
            'A mail timeout under the queue retry window is required.' => ($t = (int) ($smtp['timeout'] ?? 0)) > 0 && $t < (int) config('queue.connections.database.retry_after'),
            'The queue must be the database queue named '.self::QUEUE.'.' => config('queue.default') === 'database' && config('queue.connections.database.queue') === self::QUEUE,
            'No approved recipients are listed.' => $this->addresses() !== [],
            'An approved recipient cannot receive mail: .invalid address.' => array_filter($this->addresses(), fn ($a) => str_ends_with($a, '.invalid')) === [],
            'A confirmed recipient is not on the approved list.' => array_diff(DeliveryLog::addresses('services.outbound.mail_confirmed'), $this->addresses()) === [],
        ];

        return [...array_keys(array_filter($checks, fn ($passed) => ! $passed)), ...$this->unconfirmedSpecialRecipients()];
    }

    /**
     * MAIL_MAILER as written in the .env file itself, which is what Apache and a plain artisan
     * serve use. This process's own value comes from the start script and is not looked at here.
     */
    private function envFileMailer(): ?string
    {
        $path = app()->environmentFilePath();

        if (! is_file($path)) {
            return null;
        }

        $mailer = Dotenv::parse((string) file_get_contents($path))['MAIL_MAILER'] ?? null;

        return $mailer === null ? null : strtolower(trim($mailer));
    }

    /**
     * Approved addresses that belong to an Admin account here, or to any account in a permanent
     * database, and that the owner did not confirm for this run. A permanent database that cannot
     * be read is a failure too, because the addresses could then not be cleared.
     *
     * @return list<string>
     */
    private function unconfirmedSpecialRecipients(): array
    {
        $pending = array_values(array_diff($this->addresses(), DeliveryLog::addresses('services.outbound.mail_confirmed')));

        if ($pending === []) {
            return [];
        }

        $failures = [];

        try {
            foreach (User::whereIn(DB::raw('lower(email)'), $pending)->where('role', 'admin')->pluck('email') as $email) {
                $failures[] = 'An approved recipient is an Admin account and was not confirmed: '.DeliveryLog::maskEmail($email).'.';
            }
        } catch (Throwable $e) {
            $failures[] = 'The approved recipients could not be checked against Admin accounts.';
        }

        foreach ((array) config('mail.local_gmail_permanent_databases', Platform::PERMANENT_DATABASES) as $database) {
            try {
                foreach ($this->permanentAccounts((string) $database, $pending) as $email) {
                    $failures[] = "An approved recipient has an account in the permanent database {$database} and was not confirmed: ".DeliveryLog::maskEmail($email).'.';
                }
            } catch (Throwable $e) {
                $failures[] = "The permanent database {$database} could not be read, so the approved recipients could not be cleared against it.";
            }
        }

        return $failures;
    }

    /**
     * Read only: which of $pending have an account in $database, looked up as this process's own
     * database user. Nothing is written there.
     *
     * @param  list<string>  $pending
     * @return list<string>
     */
    private function permanentAccounts(string $database, array $pending): array
    {
        $default = config('database.default');
        $name = 'local_gmail_permanent_check';

        config(["database.connections.{$name}" => ['database' => $database] + config("database.connections.{$default}")]);
        DB::purge($name);

        try {
            return DB::connection($name)->table('users')->whereIn(DB::raw('lower(email)'), $pending)->pluck('email')->all();
        } finally {
            DB::purge($name);
        }
    }

    private function smsIsControlled(): bool
    {
        $numbers = array_filter(array_map('trim', explode(',', (string) config('services.outbound.sms'))));

        return count($numbers) === 1 && (int) config('services.msg91.send_budget') >= 1;
    }

    private function smtpLogin(): string
    {
        try {
            $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();

            if (! $transport instanceof EsmtpTransport) {
                return 'not an SMTP transport';
            }

            $transport->start();
            $transport->stop();

            return 'ok';
        } catch (Throwable $e) {
            return class_basename($e); // The message can quote the login exchange.
        }
    }

    /** @return list<array{address: string, account: string}> */
    private function recipients(): array
    {
        return array_map(function ($address) {
            $user = User::where('email', $address)->first();

            return [
                'address' => DeliveryLog::maskEmail($address),
                'account' => $user ? "{$user->role} #{$user->id}" : 'no account',
            ];
        }, $this->addresses());
    }

    /** @return list<string> */
    private function addresses(): array
    {
        return DeliveryLog::addresses('services.outbound.mail');
    }

    private function database(): string
    {
        try {
            return (string) DB::scalar('select database()');
        } catch (Throwable $e) {
            return 'unreachable';
        }
    }

    private function count(string $table, array $where = []): int
    {
        try {
            return DB::table($table)->where($where)->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function path(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
