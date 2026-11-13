<?php

/*
| Child process for TrustedProxyTest (H-2). Boots the application with a TEMPORARY environment folder,
| so its .env is loaded exactly as in production: by Laravel, after the HTTP kernel is created. This is
| what PHPUnit itself cannot show, because PHPUnit sets real environment variables before the app
| starts, and those hide a value read too early. Sends each described request through the real HTTP
| kernel and prints, as JSON, how the client address, scheme and host were resolved.
|
|   php proxy-env-probe.php <environment-folder> <requests-json>
|
| The parent passes the isolated test environment (in-memory database, array mail, discard log, test
| storage) and removes only APP_ENV, APP_URL and TRUSTED_PROXIES, so this folder's .env sets them.
*/

[$_, $environmentPath, $requestsJson] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->useEnvironmentPath($environmentPath);

// Defensive guard: refuse to run on anything but the isolated test databases, checked right after the
// configuration is loaded and BEFORE any service provider boots, so no connection is ever opened to a
// real database. In-memory SQLite, or the throwaway enotary_phpunit on this machine (Test-MariaDB.ps1).
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) {
    $default = (string) $app['config']->get('database.default');
    $connection = (array) $app['config']->get("database.connections.{$default}", []);
    $database = (string) ($connection['database'] ?? '');

    $isolated = empty($connection['url']) && match ($connection['driver'] ?? null) {
        'sqlite' => $database === ':memory:',
        'mysql', 'mariadb' => $database === 'enotary_phpunit' && in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost'], true),
        default => false,
    };

    if (! $isolated) {
        fwrite(STDERR, "proxy-env-probe refused: not an isolated test database ({$default}).\n");
        exit(3);
    }
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$results = [];

foreach (json_decode($requestsJson, true) as $name => $spec) {
    $request = Illuminate\Http\Request::create($spec['url'], 'GET', [], [], [], $spec['server']);
    $response = $kernel->handle($request);

    $resolve = function (callable $read) {
        try {
            return $read();
        } catch (Throwable $e) {
            return 'refused: '.class_basename($e);
        }
    };

    $results[$name] = [
        'status'    => $response->getStatusCode(),
        'ip'        => $request->ip(),
        'secure'    => $request->secure(),
        'host'      => $resolve(fn () => $request->getHost()),
        'login_url' => $resolve(fn () => $app['url']->to('/login')),
    ];

    $kernel->terminate($request, $response);
}

echo json_encode([
    'environment_file' => $app->environmentFilePath(),
    'environment'      => $app->environment(),
    'config_proxies'   => config('trustedproxy.proxies'),
    'requests'         => $results,
]);
