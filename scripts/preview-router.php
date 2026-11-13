<?php

// Router for scripts/Start-Preview.ps1. Laravel's server file needs the public folder
// as its working directory, and Laravel reads this setting only from $_ENV or $_SERVER.
chdir(__DIR__.'/../public');
$_ENV['LARAVEL_STORAGE_PATH'] = getenv('LARAVEL_STORAGE_PATH');

// Proves what the web process really uses. This exists only behind this router, and
// shows paths and driver names, never credentials.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__preview-gate') {
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    header('Content-Type: application/json');
    echo json_encode([
        'database'  => Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
        'storage'   => storage_path(),
        'documents' => config('filesystems.disks.local.root'),
        'mailer'    => config('mail.default'),
        'msg91_key' => filled(config('services.msg91.auth_key')),
        'allowlist' => filled(config('services.outbound.mail')) && filled(config('services.outbound.sms')),
        'app_url'   => config('app.url'),
    ]);

    return true;
}

return require __DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
