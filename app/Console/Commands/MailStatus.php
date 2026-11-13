<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints the effective mail/queue configuration WITHOUT revealing secrets.
 */
class MailStatus extends Command
{
    protected $signature = 'enotary:mail-status';

    protected $description = 'Show the effective mail and queue configuration (secrets masked)';

    public function handle(): int
    {
        $mailer = config('mail.default');
        $smtp   = config('mail.mailers.smtp');

        $this->table(['Setting', 'Value'], [
            ['Mail mode', \App\Support\Platform::mailMode()],
            ['APP_ENV', config('app.env')],
            ['APP_URL (used for links in queued emails)', config('app.url')],
            ['MAIL_MAILER', $mailer],
            ['MAIL_HOST', $smtp['host'] ?? ''],
            ['MAIL_PORT', $smtp['port'] ?? ''],
            ['MAIL_SCHEME', $smtp['scheme'] ?? '(auto: STARTTLS on 587)'],
            ['MAIL_USERNAME', $this->mask((string) ($smtp['username'] ?? ''))],
            ['MAIL_PASSWORD', filled($smtp['password'] ?? null) ? '(set, '.strlen((string) $smtp['password']).' chars)' : '(empty)'],
            ['MAIL_FROM_ADDRESS', config('mail.from.address')],
            ['MAIL_FROM_NAME', config('mail.from.name')],
            ['MAIL_ADMIN_ADDRESS', config('mail.admin_address') ?: '(not set: contact copies go to the active Admin accounts)'],
            ['QUEUE_CONNECTION', config('queue.default')],
        ]);

        $problems = [];
        if ($mailer === 'log') {
            $problems[] = 'MAIL_MAILER=log: emails are written to storage/logs/laravel.log and NOT delivered.';
        }
        if ($mailer === 'smtp') {
            if (blank($smtp['username'] ?? null) || blank($smtp['password'] ?? null)) {
                $problems[] = 'SMTP username/password are empty. Enter them in .env, then run: php artisan config:clear';
            }
            if (($smtp['host'] ?? '') === 'smtp.gmail.com' && config('mail.from.address') !== $smtp['username']) {
                $problems[] = 'Gmail rewrites the From header unless MAIL_FROM_ADDRESS equals MAIL_USERNAME.';
            }
        }
        if (in_array(config('mail.from.address'), ['hello@example.com', null, ''], true)) {
            $problems[] = 'MAIL_FROM_ADDRESS is still the placeholder.';
        }
        if (str_contains((string) config('app.url'), 'localhost') && config('app.env') !== 'production') {
            $problems[] = 'APP_URL should match the address you open in the browser (e.g. http://127.0.0.1:8000) so links in emails work.';
        }
        if (config('queue.default') === 'database') {
            $this->line('Queue is "database": php artisan serve does not start a worker, so emails stay queued. scripts\Start-Local-Gmail.ps1 starts both.');
        }

        foreach ($problems as $p) {
            $this->warn('! '.$p);
        }

        $this->info($problems ? 'Fix the warnings above before sending.' : 'Configuration looks complete.');

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }
        $at = strpos($value, '@');
        $local = $at === false ? $value : substr($value, 0, $at);
        $domain = $at === false ? '' : substr($value, $at);

        return substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2)).$domain;
    }
}
