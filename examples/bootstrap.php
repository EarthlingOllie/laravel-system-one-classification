<?php

declare(strict_types=1);

/*
 * Boots Laravel for the examples and loads their enums. Each example requires this first.
 *
 * - From a Laravel app's root, `php vendor/earthlingollie/laravel-system-one-classification/examples/yes-no.php`
 *   boots that app, so its .env and config/system-one.php apply.
 * - From a clone of this package, `php examples/yes-no.php` boots Testbench's skeleton app
 *   with the package auto-discovered. Configure it through the environment, e.g.
 *   SYSTEM_ONE_URL=http://localhost:8000 (the default) and SYSTEM_ONE_API_KEY.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;

$root = getcwd();

if ($root !== false && is_file("{$root}/artisan") && is_file("{$root}/bootstrap/app.php")) {
    require "{$root}/vendor/autoload.php";
    $app = require "{$root}/bootstrap/app.php";
    assert($app instanceof Application);
    $app->make(Kernel::class)->bootstrap();
} else {
    require dirname(__DIR__).'/vendor/autoload.php';
    Testbench::create();
}

require_once __DIR__.'/Enums/Department.php';
require_once __DIR__.'/Enums/Urgency.php';
