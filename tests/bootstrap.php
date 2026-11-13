<?php

/*
| PHPUnit bootstrap: gives every test run its own storage folder.
|
| Without this, a plain `php vendor/bin/phpunit` uses the live storage/ folder, so compiled views,
| fake disks (Storage::fake), test sinks and the test copy of the email stop file would be written
| next to the live application's files. scripts/Test-Isolated.ps1 and scripts/Test-MariaDB.ps1 set
| LARAVEL_STORAGE_PATH themselves; that choice is kept. Otherwise a folder in the system temp
| directory is used. Laravel reads LARAVEL_STORAGE_PATH from $_ENV / $_SERVER, and child processes
| started by tests inherit it through the process environment.
*/

require __DIR__.'/../vendor/autoload.php';

$storage = getenv('LARAVEL_STORAGE_PATH') ?: ($_SERVER['LARAVEL_STORAGE_PATH'] ?? null);

if (! $storage) {
    $storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enotary-phpunit-storage';
}

foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'framework/testing', 'logs'] as $dir) {
    $path = $storage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir);

    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        fwrite(STDERR, "Could not create the test storage folder {$path}.\n");
        exit(1);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
putenv('LARAVEL_STORAGE_PATH='.$storage);
