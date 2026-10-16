<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Support\ServiceProvider;

const CONFIG_FILE = __DIR__.'/../../config/system-one.php';

/**
 * Evaluates config/system-one.php with the given environment, as Laravel does at boot.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function systemOneConfigWith(array $environment): array
{
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return require CONFIG_FILE;
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

it('merges the package config so the default connection is local', function (): void {
    expect(config('system-one.default'))->toBe('local')
        ->and(config('system-one.connections.local'))->toBeArray();
});

it('points the local connection at http://localhost:8000 when SYSTEM_ONE_URL is not set', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_CONNECTION' => null,
        'SYSTEM_ONE_URL' => null,
        'SYSTEM_ONE_API_KEY' => null,
        'SYSTEM_ONE_TIMEOUT' => null,
    ]);

    expect($config)->toBe([
        'default' => 'local',
        'connections' => [
            'local' => [
                'url' => 'http://localhost:8000',
                'api_key' => null,
                'timeout' => 30.0,
            ],
        ],
    ]);
});

it('takes the local base URL, key and timeout from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_URL' => 'http://laya:8000',
        'SYSTEM_ONE_API_KEY' => 'secret',
        'SYSTEM_ONE_TIMEOUT' => '5',
    ]);

    expect($config['connections'])->toBe([
        'local' => ['url' => 'http://laya:8000', 'api_key' => 'secret', 'timeout' => 5.0],
    ]);
});

it('takes the default connection from SYSTEM_ONE_CONNECTION', function (): void {
    expect(systemOneConfigWith(['SYSTEM_ONE_CONNECTION' => 'jev'])['default'])->toBe('jev');
});

it('publishes the config file under the system-one-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(SystemOneServiceProvider::class, 'system-one-config'))
        ->toBe([realpath(CONFIG_FILE) => config_path('system-one.php')]);
});

it('copies the config into the application with vendor:publish', function (): void {
    $target = config_path('system-one.php');
    @unlink($target);

    try {
        $this->artisan('vendor:publish', ['--tag' => 'system-one-config'])->assertSuccessful();

        expect($target)->toBeFile()
            ->and(file_get_contents($target))->toBe(file_get_contents(CONFIG_FILE));
    } finally {
        @unlink($target);
    }
});
