<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

/**
 * Creates the first Admin account on a new, empty installation, from the server console.
 * CLI only: there is deliberately no web route for this.
 *
 * It refuses when any Admin row already exists, so it can never add a second Admin or replace
 * one (use enotary:admin-reset for a lost password). The password is typed at a hidden prompt,
 * never passed as an argument, so it cannot reach shell history, process lists or the log.
 * Two-factor sign-in is not set up here: with ADMIN_MFA_REQUIRED=true the Admin is sent to
 * enrolment at the first sign-in.
 */
class AdminCreate extends Command
{
    protected $signature = 'enotary:admin-create';

    protected $description = 'Create the first Admin account on an empty installation (interactive, hidden password)';

    private const EXISTS = 'An Admin account already exists. Nothing was changed. Use enotary:admin-reset for a lost password.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command must be run interactively in a terminal.');

            return self::FAILURE;
        }

        // One run at a time: a second terminal is refused while this one waits for input, so two
        // people cannot each create a "first" Admin. The lock lives in the shared cache store.
        $lock = Cache::lock('enotary:admin-create', 900);

        if (! $lock->get()) {
            $this->error('enotary:admin-create is already running in another terminal. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            return $this->create();
        } finally {
            $lock->release();
        }
    }

    private function create(): int
    {
        if ($this->adminExists()) {
            $this->error(self::EXISTS);

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Admin name'));
        $email = strtolower(trim((string) $this->ask('Admin email address')));
        $mobile = trim((string) $this->ask('Admin 10-digit mobile number'));
        $password = (string) $this->secret('Admin password (hidden, minimum 12 characters)', false);
        $confirmation = (string) $this->secret('Repeat the password', false);

        if (! hash_equals($password, $confirmation)) {
            $this->error('The passwords do not match. Nothing was changed.');

            return self::FAILURE;
        }

        // The same password rule as enotary:admin-reset: 12 characters and at most 72 bytes.
        $validator = Validator::make(compact('name', 'email', 'mobile', 'password'), [
            'name' => 'required|string|min:2|max:255',
            'email' => 'required|email:rfc|max:255|unique:users,email',
            'mobile' => 'required|digits:10|unique:users,mobile',
            'password' => ['required', 'string', PasswordPolicy::maxBytes(), Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first().' Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $admin = $this->store($name, $email, $mobile, $password);
        } catch (Throwable $e) {
            // The SQL error would print the row's values, the password hash among them, to the
            // terminal and the log. Only the kind of failure is reported.
            report(new RuntimeException('enotary:admin-create failed: '.class_basename($e).' (code '.$e->getCode().'). Nothing was saved.'));
            $this->error('The Admin account could not be saved. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $admin) {
            $this->error(self::EXISTS);

            return self::FAILURE;
        }

        $this->info('Admin account created (id '.$admin->id.'). Sign in from the home page and set up two-factor sign-in.');

        return self::SUCCESS;
    }

    /** Every users row, including archived ones: an existing Admin in any state blocks this. */
    private function adminExists(): bool
    {
        return DB::table('users')->where('role', 'admin')->exists();
    }

    /** The user and its audit entry together, or neither. Null when an Admin appeared meanwhile. */
    private function store(string $name, string $email, string $mobile, string $password): ?User
    {
        return DB::transaction(function () use ($name, $email, $mobile, $password) {
            if ($this->adminExists()) {
                return null;
            }

            $admin = new User;
            $admin->forceFill([
                'name' => $name,
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make($password),
                'role' => 'admin',
                'remember_token' => Str::random(60),
            ])->save();

            AuditLog::recordOrFail('admin.created_cli', $admin, [], null);

            return $admin;
        });
    }
}
