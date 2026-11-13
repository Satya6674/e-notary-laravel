<?php

/*
| Run by TestEnvironmentIsolationTest as a CHILD process (as schedule:run starts commands): boots the
| application with the environment it inherited and prints, as JSON, only the facts that prove it is
| isolated. It prints no secret: credentials are reported as "dummy" or "other", never as values.
*/

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$default = config('database.default');

echo json_encode([
    'env_file'       => basename($app->environmentFilePath()),
    'environment'    => $app->environment(),
    'storage'        => storage_path(),
    'database'       => (string) config("database.connections.{$default}.database"),
    'db_driver'      => $default,
    'mail'           => config('mail.default'),
    'live_mail'      => config('mail.live_enabled'),
    'smtp_host'      => config('mail.mailers.smtp.host'),
    'smtp_account'   => config('mail.mailers.smtp.username') === 'phpunit-no-account' ? 'dummy' : 'other',
    'admin_address'  => (string) config('mail.admin_address'),
    'msg91_key'      => filled(config('services.msg91.auth_key')) ? 'set' : 'empty',
    'app_key'        => config('app.key') === 'base64:yv0nszAzIq6zHk175e3THX3l2qQk2PJBKzLSFEyTrRc=' ? 'test' : 'other',
    'queue'          => config('queue.default'),
    'scheduler_safe' => config('scheduler.safe_mode'),
]);
