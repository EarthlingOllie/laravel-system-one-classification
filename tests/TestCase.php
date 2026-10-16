<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use UnexpectedValueException;

/**
 * Base Testbench case for the Feature suite.
 *
 * The providers are read from composer.json's `extra.laravel.providers`, which is exactly
 * what Laravel package auto-discovery registers in a real application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    public static function discoveredProviders(): array
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $providers = is_array($composer)
            && is_array($composer['extra'] ?? null)
            && is_array($composer['extra']['laravel'] ?? null)
            ? $composer['extra']['laravel']['providers'] ?? []
            : [];

        if (! is_array($providers) || ! array_is_list($providers)) {
            throw new UnexpectedValueException('composer.json extra.laravel.providers must be a list.');
        }

        return array_map(static function (mixed $provider): string {
            if (! is_string($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                throw new UnexpectedValueException('Not a service provider: '.var_export($provider, true));
            }

            return $provider;
        }, $providers);
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return self::discoveredProviders();
    }
}
