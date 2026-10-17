<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\Example;

/*
 * Every example in examples/ that needs a model server, run as `php examples/<name>.php` against
 * the Laya container at SYSTEM_ONE_URL (and SYSTEM_ONE_API_KEY), which the scripts inherit.
 * tests/Docs/ExamplesTest.php checks that every example is run here or there.
 */

$examples = [];

foreach (Example::SERVER_EXPECTATIONS as $name => $patterns) {
    $examples[$name] = [$name, $patterns];
}

it('runs the example against the server and prints what the README says it does', function (string $name, array $patterns): void {
    $run = Example::run($name);

    expect($run->exitCode)->toBe(0, $run->transcript())
        ->and($run->errors)->toBe('', $run->transcript());

    foreach ($patterns as $pattern) {
        expect($run->output)->toMatch($pattern, $run->transcript());
    }
})->with($examples);
