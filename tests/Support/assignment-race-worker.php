<?php

/**
 * One side of a real concurrent assignment, run as its own PHP process by
 * tests/Feature/AssignmentConcurrencyTest.php. It is never run by hand.
 *
 *   php assignment-race-worker.php <mode> <admin> <application> <notary> <seen> <startAt> <holdMs> <waitFor> <out>
 *
 * mode    plain: assign at <startAt>, after <waitFor> exists if one is given.
 *         hold:  lock the application row, mark <out>.locked, assign, keep the lock <holdMs>, commit.
 * seen    the Notary id the administrator's page showed, or "none".
 *
 * It refuses to run unless the database is the throwaway enotary_phpunit on this machine and
 * mail, queue, cache and SMS are all fakes, so it cannot reach real data or send anything.
 */

use App\Models\NotaryRequest;
use App\Models\User;
use App\Services\NotaryAssignmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

[$self, $mode, $adminId, $applicationId, $notaryId, $seen, $startAt, $holdMs, $waitFor, $out] = $argv;

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

Auth::setUser(User::findOrFail((int) $adminId));
$service = app(NotaryAssignmentService::class);
$seenId = $seen === 'none' ? null : (int) $seen;

// Connect before the start line, so both sides begin the race at the same moment.
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

if ($waitFor !== '-') {
    $deadline = microtime(true) + 20;
    while (! file_exists($waitFor) && microtime(true) < $deadline) {
        usleep(1000);
    }
}

$began = microtime(true);

if ($mode === 'hold') {
    DB::beginTransaction();
    NotaryRequest::whereKey((int) $applicationId)->lockForUpdate()->first();
    file_put_contents($out.'.locked', '1');
    $outcome = $service->assign(User::find((int) $adminId), (int) $applicationId, (int) $notaryId, true, $seenId);
    usleep((int) $holdMs * 1000);
    DB::commit();
} else {
    $outcome = $service->assign(User::find((int) $adminId), (int) $applicationId, (int) $notaryId, true, $seenId);
}

$write(['outcome' => $outcome, 'message' => $service->message, 'began' => $began, 'ended' => microtime(true), 'notary' => (int) $notaryId]);
