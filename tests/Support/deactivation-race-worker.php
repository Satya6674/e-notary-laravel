<?php

/**
 * One side of a real concurrent request, run as its own PHP process by
 * tests/Feature/DeactivationConcurrencyTest.php and tests/Feature/ResendConcurrencyTest.php.
 * It is never run by hand.
 *
 *   php deactivation-race-worker.php <action> <hold> <actor> <target> <application> <apps> <appts> <startAt> <holdMs> <waitFor> <out>
 *
 * action  book:       the actor (a Citizen) books an appointment for <application>, through
 *                     POST /appointments, the real route with its middleware.
 *         deactivate: the actor (an Admin) deactivates <target> through DELETE /admin/users/{id},
 *                     confirming <apps> open applications and <appts> open appointments.
 *         resend:     the actor (an Admin) asks for a new password link for the account deletion
 *                     <target>, through POST /admin/account-deletions/{id}/reset-link.
 * hold    0: send the request at <startAt>, after <waitFor> exists if one is given.
 *         1: send it inside an outer transaction, mark <out>.locked once it has returned (its row
 *            locks are now held), keep the transaction open <holdMs>, then commit.
 *
 * It refuses to run unless the database is the throwaway enotary_phpunit on this machine and
 * mail, queue, cache, session and SMS are all fakes, so it cannot reach real data or send anything.
 */

use App\Models\User;
use App\Support\Platform;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$self, $action, $hold, $actorId, $targetId, $applicationId, $apps, $appts, $startAt, $holdMs, $waitFor, $out] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$write = fn (array $data) => file_put_contents($out, json_encode($data));

$default = config('database.default');
$connection = config("database.connections.{$default}");
// Names of the failed checks only, never a value.
$unsafe = array_keys(array_filter([
    'env' => ! $app->environment('testing'),
    'driver' => ! in_array($default, ['mysql', 'mariadb'], true),
    'url' => ! empty($connection['url']),
    'database' => ($connection['database'] ?? null) !== 'enotary_phpunit',
    'host' => ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true),
    'mail' => config('mail.default') !== 'array',
    'queue' => config('queue.default') !== 'sync',
    'cache' => config('cache.default') !== 'array',
    'session' => config('session.driver') !== 'array',
    'msg91' => filled(config('services.msg91.auth_key')),
]));

if ($unsafe) {
    $write(['error' => 'refused: environment is not the isolated test setup', 'failed' => $unsafe]);
    exit(3);
}

try {

$request = match ($action) {
    'book' => Request::create('/appointments', 'POST', [
        'request_id' => (int) $applicationId,
        'scheduled_at' => now(Platform::DISPLAY_TIMEZONE)->addDays(3)->format('Y-m-d H:i'),
        'mode' => 'video',
    ]),
    'deactivate' => Request::create('/admin/users/'.(int) $targetId, 'DELETE', [
        'reason' => 'Synthetic race deactivation.',
        'open_applications' => (int) $apps,
        'open_appointments' => (int) $appts,
    ]),
    // <target> is the account deletion record here.
    'resend' => Request::create('/admin/account-deletions/'.(int) $targetId.'/reset-link', 'POST'),
    // No request: an UPDATE by primary key, which locks that one row only (as a direct SQL change
    // would). Used with hold=1 to leave <target> deactivated but uncommitted for <holdMs>.
    'deactivate-row' => null,
};

// Signed in as a test's actingAs() does. The guard needs the request bound to be built.
if ($request) {
    $app->instance('request', $request);
    $app['auth']->guard()->setUser(User::findOrFail((int) $actorId));
    // As in tests/TestCase.php: the acting Admin has just confirmed the password (step-up window
    // open). StartSession keeps attributes already on the store when it loads the session.
    $app['session']->driver()->put(\App\Support\AdminReauth::SESSION_KEY, time());
}

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

if (! $request) {
    DB::beginTransaction();
    DB::table('users')->where('id', (int) $targetId)->update(['deactivated_at' => now()]);
    file_put_contents($out.'.locked', '1');
    usleep((int) $holdMs * 1000);
    DB::commit();
    $write(['code' => 302, 'status' => 'row deactivated', 'error' => null, 'errors' => [], 'began' => $began, 'ended' => microtime(true)]);
    exit(0);
}

if ($hold === '1') {
    DB::beginTransaction();
}

$response = $kernel->handle($request);

if ($hold === '1') {
    file_put_contents($out.'.locked', '1');
    usleep((int) $holdMs * 1000);
    DB::commit();
}

$ended = microtime(true);
$session = $request->hasSession() ? $request->session() : null;

$write([
    'code' => $response->getStatusCode(),
    'status' => $session?->get('status'),
    'error' => $session?->get('error'),
    'errors' => $session?->get('errors')?->all() ?? [],
    'began' => $began,
    'ended' => $ended,
]);

$kernel->terminate($request, $response);

} catch (Throwable $e) {
    // A failure must reach the test: with the HTTP kernel booted, an uncaught one would be rendered and exit 0.
    if (DB::transactionLevel() > 0) {
        DB::rollBack(0);
    }
    $write(['error' => get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()]);
    exit(4);
}
