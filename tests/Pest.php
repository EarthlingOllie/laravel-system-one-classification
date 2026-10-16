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
| Integration tests talk to a real Laya server; they are grouped as
| `integration` and are not part of the default suite (see phpunit.xml.dist).
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->group('integration')->in('Integration');
