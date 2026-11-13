<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminMfa;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery for an Admin who lost both the authenticator and the recovery codes: removes that
 * Admin's two-factor sign-in from the server console. CLI only (physical/server access), never a
 * web route. Audited (enforced) and the Admin is emailed. The Admin should set it up again at once.
 */
class AdminMfaReset extends Command
{
    protected $signature = 'enotary:admin-mfa-reset {email : The Admin account email}';

    protected $description = 'Remove two-factor sign-in from an Admin account (console recovery)';

    public function handle(AdminMfa $mfa): int
    {
        if (! AdminMfa::available()) {
            $this->error('Two-factor sign-in is not installed (migration 2026_11_10_000031 has not run).');

            return self::FAILURE;
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim((string) $this->argument('email')))])->first();

        if (! $user || ! $user->isAdmin()) {
            $this->error('No Admin account with that email. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $mfa->factor($user)) {
            $this->info('Two-factor sign-in is not set up on that account. Nothing to do.');

            return self::SUCCESS;
        }

        $masked = Str::mask($user->email, '*', 1, max(strpos($user->email, '@') - 1, 1));

        if ($this->input->isInteractive() && ! $this->confirm("Remove two-factor sign-in from {$masked}?")) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $mfa->disable($user, null, 'admin.mfa_reset_cli');
        $this->info("Two-factor sign-in removed from {$masked}. Sign in and set it up again.");

        return self::SUCCESS;
    }
}
