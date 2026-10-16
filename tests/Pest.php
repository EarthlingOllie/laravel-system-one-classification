<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Suites
|--------------------------------------------------------------------------
|
| Unit, Contract and Arch tests are plain Pest tests with no framework.
| Feature tests boot a Laravel application through Orchestra Testbench.
| Integration tests talk to a real Laya server. They are grouped as
| `integration`, are not part of the default suite (see phpunit.xml.dist),
| and skip themselves when no server is listening at SYSTEM_ONE_URL.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->group('integration')
    ->beforeEach(function (): void {
        $url = layaUrl();

        if (! layaIsReachable($url)) {
            test()->markTestSkipped("No Laya server at {$url}; start one (e.g. `composer laya:up`) or set SYSTEM_ONE_URL.");
        }
    })
    ->in('Integration');

/** Base URL of the Laya server used by the Integration suite. */
function layaUrl(): string
{
    $url = getenv('SYSTEM_ONE_URL');

    return is_string($url) && $url !== '' ? $url : 'http://localhost:8000';
}

function layaIsReachable(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);

    if (! is_string($host)) {
        return false;
    }

    // A refused connection is the expected answer when no server runs, not a test warning.
    set_error_handler(static fn (): bool => true);

    try {
        $socket = fsockopen($host, $port, $errorCode, $errorMessage, 1.0);
    } finally {
        restore_error_handler();
    }

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}
