<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench app (testbench.yaml, workbench/) is what `vendor/bin/testbench serve`
 * runs. Its status page is the browser-visible proof that the package boots.
 */

uses(WithWorkbench::class);

it('serves a status page that reports the provider as loaded', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('System One Classification')
        ->assertSee(SystemOneServiceProvider::class)
        ->assertSee('data-provider-loaded="yes"', escape: false);
});
