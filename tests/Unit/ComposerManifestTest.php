<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function composerManifest(): array
{
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $manifest;
}

it('is published under the planned package name', function (): void {
    expect(composerManifest()['name'])->toBe('earthlingollie/laravel-system-one-classification');
});

it('requires PHP 8.4 and Laravel 13', function (): void {
    expect(composerManifest()['require'])->toMatchArray([
        'php' => '^8.4',
        'illuminate/http' => '^13.0',
        'illuminate/support' => '^13.0',
    ]);
});

it('autoloads the package namespace from src/ with PSR-4', function (): void {
    expect(composerManifest()['autoload'])->toBe([
        'psr-4' => ['EarthlingOllie\\SystemOneClassification\\' => 'src/'],
    ]);
});

it('exposes a composer script for every gate plus one that runs them all', function (): void {
    expect(composerManifest()['scripts'])->toHaveKeys([
        'test', 'test:integration', 'test:coverage', 'analyse', 'format', 'format:check', 'mutate', 'check',
    ]);
});
