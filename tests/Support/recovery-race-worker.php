<?php

/**
 * One side of a real concurrent recovery or registration, run as its own PHP process by
 * tests/Feature/RecoveryConcurrencyTest.php. It is never run by hand.
 *
 *   php recovery-race-worker.php <action> <hold> <actor> <target> <extraJson> <startAt> <holdMs> <waitFor> <out>
 *
 * action  approve:  the actor (an Admin) approves recovery request <target> through
 *                   POST /admin/account-recovery/{id}/approve, the real route.
 *         reject:   the actor (an Admin) rejects it through POST /admin/account-recovery/{id}/reject.
 *         cancel:   the actor (an Admin) closes it through POST /admin/account-recovery/{id}/cancel.
 *         replace-start:   the actor (an Admin) proposes a new address for account <target>, with
 *                          the form fields in <extraJson>.
 *         replace-confirm: a guest opens and confirms replacement <target> with <extraJson> token.
 *         replace-cancel:  the actor (an Admin) cancels replacement <target>.
 *         register: a guest signs up through POST /custom-register with <extraJson> email/mobile.
 *         submit:   AccountRecoveryService::submit() for the actor and archived account <target>
 *                   (the step after the emailed code was checked).
 * hold    0: run at <startAt>, after <waitFor> exists if one is given.
 *         1: run inside an outer transaction, mark <out>.locked once done (its row locks are
 *            now held), keep the transaction open <holdMs>, then commit.
 *
 * It refuses to run unless the database is the throwaway enotary_phpunit on this machine and
 * mail, queue, cache, session and SMS are all fakes, so it cannot reach real data or send anything.
 */

use App\Models\User;
use App\Services\AccountRecoveryService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$self, $action, $hold, $actorId, $targetId, $extra, $startAt, $holdMs, $waitFor, $out] = $argv;

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
    $extra = json_decode($extra, true) ?: [];

    $request = match ($action) {
        'approve' => Request::create('/admin/account-recovery/'.(int) $targetId.'/approve', 'POST', ['identity_checked' => '1']),
        'reject' => Request::create('/admin/account-recovery/'.(int) $targetId.'/reject', 'POST', ['reason' => 'Synthetic race rejection.']),
        'cancel' => Request::create('/admin/account-recovery/'.(int) $targetId.'/cancel', 'POST', ['reason' => 'Synthetic race closing.']),
        'replace-start' => Request::create('/admin/users/'.(int) $targetId.'/email-replacement', 'POST', $extra),
        'replace-confirm' => Request::create('/email-replacement/'.(int) $targetId.'/'.$extra['token'], 'POST'),
        'replace-cancel' => Request::create('/admin/email-replacements/'.(int) $targetId.'/cancel', 'POST', ['reason' => 'Synthetic race cancel.']),
        'register' => Request::create('/custom-register', 'POST', [
            'name' => 'Race Newcomer', 'email' => $extra['email'], 'mobile' => $extra['mobile'],
            'password' => 'Race-password-1', 'password_confirmation' => 'Race-password-1', 'role' => 'public', 'accept_terms' => '1',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']),
        'submit' => null,
    };

    if ($request) {
        $app->instance('request', $request);
        if ((int) $actorId > 0) {
            $app['auth']->guard()->setUser(User::findOrFail((int) $actorId));
            // As in tests/TestCase.php: the acting Admin has just confirmed the password.
            $app['session']->driver()->put(\App\Support\AdminReauth::SESSION_KEY, time());
        }
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

    if ($hold === '1') {
        DB::beginTransaction();
    }

    if ($request) {
        $response = $kernel->handle($request);
        $code = $response->getStatusCode();
        $session = $request->hasSession() ? $request->session() : null;
        $result = [
            'status' => $session?->get('status'),
            'error' => $session?->get('error'),
            'message' => $action === 'register' ? (json_decode((string) $response->getContent(), true)['message'] ?? null) : null,
        ];
    } else {
        $recovery = app(AccountRecoveryService::class)->submit(User::findOrFail((int) $actorId), (int) $targetId);
        $code = 200;
        $result = ['request' => $recovery?->id];
    }

    if ($hold === '1') {
        file_put_contents($out.'.locked', '1');
        usleep((int) $holdMs * 1000);
        DB::commit();
    }

    $write(['code' => $code, 'began' => $began, 'ended' => microtime(true)] + $result);

    if ($request) {
        $kernel->terminate($request, $response);
    }
} catch (Throwable $e) {
    // A failure must reach the test: with the HTTP kernel booted, an uncaught one would be rendered and exit 0.
    if (DB::transactionLevel() > 0) {
        DB::rollBack(0);
    }
    $write(['error' => get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()]);
    exit(4);
}
