<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Laravel\Install\ComposeFiles;
use Symfony\Component\Yaml\Yaml;

function packageDir(): string
{
    return dirname(__DIR__, 4);
}

function sailFixture(string $name): string
{
    return (string) file_get_contents(packageDir().'/tests/Fixtures/sail/'.$name);
}

/**
 * Whether every line of $original appears in $updated in the same order (lines were only added).
 *
 * @param  list<string>  $original
 * @param  list<string>  $updated
 */
function isSubsequence(array $original, array $updated): bool
{
    $i = 0;

    foreach ($updated as $line) {
        if ($i < count($original) && $line === $original[$i]) {
            $i++;
        }
    }

    return $i === count($original);
}

/** @return array<mixed> */
function parsedYaml(string $yaml): array
{
    $parsed = Yaml::parse($yaml);
    assert(is_array($parsed));

    return $parsed;
}

it('builds the standalone file from the package compose file, pointing the build at the installed package', function (): void {
    $yaml = (new ComposeFiles(packageDir()))->standalone(dirname(packageDir(), 2));
    $source = parsedYaml((string) file_get_contents(packageDir().'/docker/compose.yaml'));
    $source['services']['laya']['build']['context'] = './'.basename(dirname(packageDir())).'/'.basename(packageDir()).'/docker/laya';

    expect(parsedYaml($yaml))->toBe($source)
        ->and($yaml)->toStartWith("# Local Laya server for this app, written by `php artisan system-one:install`.\n")
        ->and($yaml)->toContain('docker compose -f compose.system-one.yaml up -d --wait')
        ->and($yaml)->not->toContain('composer laya:up');
});

it('uses an absolute build context when the package is not inside the app', function (): void {
    $yaml = (new ComposeFiles(packageDir()))->standalone('/app');

    expect(parsedYaml($yaml)['services']['laya']['build']['context'])->toBe(packageDir().'/docker/laya');
});

it('does not treat a sibling directory with the same prefix as inside the app', function (): void {
    expect(parsedYaml((new ComposeFiles(packageDir()))->standalone(substr(packageDir(), 0, -1)))['services']['laya']['build']['context'])
        ->toBe(packageDir().'/docker/laya');
});

it('refuses a package compose file it cannot rewrite', function (string $compose, string $reason): void {
    $dir = sys_get_temp_dir().'/system-one-compose-'.bin2hex(random_bytes(4));
    mkdir($dir.'/docker', recursive: true);
    file_put_contents($dir.'/docker/compose.yaml', $compose);

    try {
        expect(fn () => (new ComposeFiles($dir))->standalone('/app'))
            ->toThrow(InvalidConfiguration::class, "{$dir}/docker/compose.yaml {$reason}.");
    } finally {
        unlink($dir.'/docker/compose.yaml');
        rmdir($dir.'/docker');
        rmdir($dir);
    }
})->with([
    'no build context' => ["# x\nname: s\nservices:\n  laya:\n    build: .\n", "has no 'context: ./laya' line to point at the installed package"],
    'no name line' => ["# x\nservices:\n  laya:\n    build:\n      context: ./laya\n", "has no single top-level 'name:' line ending its header"],
]);

it('refuses a package compose file without a laya service', function (): void {
    $dir = sys_get_temp_dir().'/system-one-compose-'.bin2hex(random_bytes(4));
    mkdir($dir.'/docker', recursive: true);
    file_put_contents($dir.'/docker/compose.yaml', "services:\n  other:\n    image: x\n");

    try {
        (new ComposeFiles($dir))->addToSail("services:\n    app:\n        image: x\n", '/app');
    } finally {
        unlink($dir.'/docker/compose.yaml');
        rmdir($dir.'/docker');
        rmdir($dir);
    }
})->throws(InvalidConfiguration::class, '/docker/compose.yaml has no laya service.');

it('adds a laya service and its volume to a Sail file, leaving every existing line as it was', function (): void {
    $original = sailFixture('docker-compose.yml');

    $updated = (new ComposeFiles(packageDir()))->addToSail($original, dirname(packageDir()).'/');
    assert($updated !== null);
    $parsed = parsedYaml($updated);
    $before = parsedYaml($original);

    expect(array_keys($parsed['services']))->toBe(['laya', 'laravel.test', 'mysql'])
        ->and($parsed['services']['laravel.test'])->toBe($before['services']['laravel.test'])
        ->and($parsed['services']['mysql'])->toBe($before['services']['mysql'])
        ->and($parsed['networks'])->toBe($before['networks'])
        ->and($parsed['volumes'])->toBe(['sail-laya' => ['driver' => 'local'], 'sail-mysql' => ['driver' => 'local']])
        // Every original line is still there, in order: only lines were added.
        ->and(isSubsequence(explode("\n", $original), explode("\n", $updated)))->toBeTrue();

    $laya = $parsed['services']['laya'];
    expect($laya['build'])->toBe(['context' => './'.basename(packageDir()).'/docker/laya'])
        ->and($laya['pull_policy'])->toBe('build')
        ->and($laya['volumes'])->toBe(['sail-laya:/home/laya/.cache/huggingface'])
        ->and($laya['networks'])->toBe(['sail'])
        ->and($laya['environment']['LAYA_DEFAULT_MODEL'])->toBe('${LAYA_DEFAULT_MODEL:-english}')
        ->and($laya['healthcheck']['test'])->toBe(['CMD', 'python', '/opt/laya/healthcheck.py'])
        ->and($laya['ports'])->toBe(['127.0.0.1:${LAYA_HOST_PORT:-8000}:8000'])
        ->and($updated)->toContain("services:\n    laya:\n        image: 'earthlingollie/laya-serve:0.4.8-cpu'\n");
});

it('follows the file\'s indentation and adds a volumes section when there is none', function (): void {
    $original = sailFixture('no-volumes.yml');

    $updated = (new ComposeFiles(packageDir()))->addToSail($original, '/app');
    assert($updated !== null);

    expect($updated)->toStartWith("# A Sail file trimmed by hand: two-space indentation and no volumes section.\nservices:\n  laya:\n")
        ->and($updated)->toContain("services:\n  laya:\n    image: ")
        ->and($updated)->toEndWith("    driver: bridge\nvolumes:\n  sail-laya:\n    driver: local\n")
        ->and(parsedYaml($updated)['services']['laya']['networks'])->toBe(['sail']);
});

it('appends a volumes section to a file without a final newline', function (): void {
    $updated = (new ComposeFiles(packageDir()))->addToSail("services:\n    app:\n        image: x", '/app');
    assert($updated !== null);

    expect($updated)->toEndWith("        image: x\nvolumes:\n    sail-laya:\n        driver: local\n")
        ->and(parsedYaml($updated)['services'])->toHaveKeys(['laya', 'app'])
        ->and(parsedYaml($updated)['services']['laya'])->not->toHaveKey('networks');
});

it('joins the sail network only when the file defines one', function (): void {
    $compose = "services:\n    app:\n        image: x\nnetworks:\n    other:\n        driver: bridge\n";

    $updated = (new ComposeFiles(packageDir()))->addToSail($compose, '/app');
    assert($updated !== null);

    expect(parsedYaml($updated)['services']['laya'])->not->toHaveKey('networks');
});

it('changes nothing when the laya service and its volume are already there', function (): void {
    $files = new ComposeFiles(packageDir());
    $once = $files->addToSail(sailFixture('docker-compose.yml'), '/app');
    assert($once !== null);

    expect($files->addToSail($once, '/app'))->toBeNull();
});

it('keeps an existing laya service as it is and only adds the missing volume', function (): void {
    $compose = "services:\n    laya:\n        image: mine\nvolumes:\n    other:\n        driver: local\n";

    $updated = (new ComposeFiles(packageDir()))->addToSail($compose, '/app');

    expect($updated)->toBe("services:\n    laya:\n        image: mine\nvolumes:\n    sail-laya:\n        driver: local\n    other:\n        driver: local\n");
});

it('refuses a file it cannot safely edit', function (string $compose, string $reason): void {
    expect(fn () => (new ComposeFiles(packageDir()))->addToSail($compose, '/app'))
        ->toThrow(InvalidConfiguration::class, $reason);
})->with([
    'not YAML' => ["services: [\n", 'The Sail Compose file is not valid YAML: Malformed inline YAML string at line 2..'],
    'not a mapping' => ['- a', 'has no services section'],
    'a scalar' => ['hello', 'has no services section'],
    'services a list' => ["services:\n- app\n", 'has no services section'],
    'no services' => ["volumes:\n    a: {}\n", 'has no services section'],
    'services not a map' => ["services: 3\n", 'has no services section'],
    'flow-style services' => ["services: {app: {image: x}}\n", "has no block-style 'services:' line to add to"],
    'flow-style volumes' => ["services:\n    app:\n        image: x\nvolumes: {a: {}}\n", "has no block-style 'volumes:' line to add to"],
    'volumes not a map' => ["services:\n    app:\n        image: x\nvolumes: 3\n", "'volumes' is not a map"],
]);
