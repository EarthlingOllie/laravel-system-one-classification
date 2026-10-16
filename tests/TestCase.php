<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

/**
 * Base Testbench case for the Feature suite.
 *
 * The providers are read from composer.json's `extra.laravel.providers`, which is exactly
 * what Laravel package auto-discovery registers in a real application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return self::discoveredProviders();
    }

    /**
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    public static function discoveredProviders(): array
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($composer)) {
            throw new RuntimeException('composer.json must decode to an object.');
        }

        /** @var list<class-string<\Illuminate\Support\ServiceProvider>> $providers */
        $providers = $composer['extra']['laravel']['providers'] ?? [];

        return $providers;
    }
}
