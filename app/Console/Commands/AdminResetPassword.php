<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a new password on the single admin account from the server console.
 * CLI only: there is deliberately no web route for this.
 *
 * The password is typed at a hidden prompt and never passed as an argument, so
 * it cannot reach shell history, process lists or the log. If the terminal
 * cannot hide input the command stops rather than echo it.
 */
class AdminResetPassword extends Command
{
    protected $signature = 'enotary:admin-reset';

    protected $description = 'Set a new password on the admin account (interactive, hidden prompt)';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command must be run interactively in a terminal.');

            return self::FAILURE;
        }

        $admins = User::where('role', 'admin')->get();

        if ($admins->count() !== 1) {
            $this->error('Expected exactly one admin account, found '.$admins->count().'. Nothing was changed.');

            return self::FAILURE;
        }

        $admin = $admins->first();
        $this->line('Admin account: '.Str::mask($admin->email, '*', 1, max(strpos($admin->email, '@') - 1, 1)));

        $password = (string) $this->secret('New admin password (hidden, minimum 12 characters)', false);
        $confirmation = (string) $this->secret('Repeat the new password', false);

        if (! hash_equals($password, $confirmation)) {
            $this->error('The passwords do not match. Nothing was changed.');

            return self::FAILURE;
        }

        // Stricter than the web policy (12 characters), with the same 72-byte limit: bcrypt reads only
        // the first 72 bytes, and max:72 would count characters, letting e.g. a Hindi password through.
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', PasswordPolicy::maxBytes(), Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password').' Nothing was changed.');

            return self::FAILURE;
        }

        $admin->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        AuditLog::record('admin.password_reset_cli', $admin);

        $this->info('Admin password updated. Log in from the home page.');

        return self::SUCCESS;
    }
}
