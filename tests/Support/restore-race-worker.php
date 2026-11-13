<?php

/**
 * One administrator restoring a deleted account, run as its own PHP process by
 * tests/Feature/RestoreConcurrencyTest.php. It is never run by hand.
 *
 *   php restore-race-worker.php <admin> <user> <startAt> <out>
 *
 * It refuses to run unless the database is the throwaway enotary_phpunit on this machine and
 * mail, queue, cache and SMS are all fakes, so it cannot reach real data or send anything.
 */

use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

[$self, $adminId, $userId, $startAt, $out] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$write = fn (array $data) => file_put_contents($out, json_encode($data));

$default = config('database.default');
$connection = config("database.connections.{$default}");

// Names of the failed checks only, never a value.
$unsafe = array_keys(array_filter([
    'driver' => ! in_array($default, ['mysql', 'mariadb'], true),
    'url' => ! empty($connection['url']),
    'database' => ($connection['database'] ?? null) !== 'enotary_phpunit',
    'host' => ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true),
    'mail' => config('mail.default') !== 'array',
    'queue' => config('queue.default') !== 'sync',
    'cache' => config('cache.default') !== 'array',
    'msg91' => filled(config('services.msg91.auth_key')),
]));

if ($unsafe) {
    $write(['error' => 'refused: environment is not the isolated test setup', 'failed' => $unsafe]);
    exit(3);
}

$admin = User::findOrFail((int) $adminId);
Auth::setUser($admin);
$service = app(AccountDeletionService::class);

// Connect before the start line, so every worker begins at the same moment.
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$began = microtime(true);

try {
    $service->restore($admin, User::findOrFail((int) $userId), 'Synthetic concurrent restore.');
    $outcome = 'restored';
    $message = null;
} catch (AuthorizationException $e) {
    $outcome = 'refused';
    $message = $e->getMessage();
}

$write(['outcome' => $outcome, 'message' => $message, 'began' => $began, 'ended' => microtime(true)]);
