<?php

/**
 * One concurrent OTP request, run as its own PHP process by tests/Feature/OtpConcurrencyTest.php.
 * It is never run by hand.
 *
 *   php otp-race-worker.php <mobile> <flow> <ip> <startAt> <out>
 *
 * It refuses to run unless the database is the throwaway enotary_phpunit on this machine, mail
 * and queue are fakes, the cache (shared by the workers for locks and counters) lives in that same
 * throwaway database, and no SMS key is loaded. The SMS provider is replaced by a stand-in that
 * sends nothing, so no message can leave even if every other guard failed.
 */

use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[$self, $mobile, $flow, $ip, $startAt, $out] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$write = fn (array $data) => file_put_contents($out, json_encode($data));

$default = config('database.default');
$connection = config("database.connections.{$default}");
$cacheStore = config('cache.default');

// Names of the failed checks only, never a value.
$unsafe = array_keys(array_filter([
    'driver' => ! in_array($default, ['mysql', 'mariadb'], true),
    'url' => ! empty($connection['url']),
    'database' => ($connection['database'] ?? null) !== 'enotary_phpunit',
    'host' => ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true),
    'mail' => config('mail.default') !== 'array',
    'queue' => config('queue.default') !== 'sync',
    'cache' => $cacheStore !== 'database' || config('cache.stores.database.connection') !== null || config('cache.stores.database.lock_connection') !== null,
    'msg91' => filled(config('services.msg91.auth_key')),
]));

if ($unsafe) {
    $write(['error' => 'refused: environment is not the isolated test setup', 'failed' => $unsafe]);
    exit(3);
}

// Nothing is ever sent: the stand-in only records that a send was asked for.
$app->instance(SmsService::class, new class extends SmsService
{
    public function send(string $mobile, string $otp): bool
    {
        $this->lastRequestId = 'race-stand-in';

        return true;
    }
});

$service = app(OtpService::class);

// Connect before the start line, so every worker begins at the same moment.
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$began = microtime(true);
$outcome = $service->issue($mobile, $ip, $flow);

$write(['outcome' => $outcome, 'began' => $began, 'ended' => microtime(true), 'mobile' => $mobile, 'flow' => $flow]);
