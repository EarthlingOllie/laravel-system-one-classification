<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\Commands\HealthCommand;
use EarthlingOllie\SystemOneClassification\Laravel\Commands\InstallCommand;
use EarthlingOllie\SystemOneClassification\Laravel\Commands\TryCommand;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\SystemOneFake;
use EarthlingOllie\SystemOneClassification\Tests\Support\Example;
use EarthlingOllie\SystemOneClassification\Tests\Support\Readme;
use Illuminate\Console\Command;

/*
 * README.md against the code, so the docs cannot drift from it: every PHP snippet parses and
 * uses only API that exists, the enums match the runnable examples', and every command,
 * option, env var and composer script it names is real.
 */

/**
 * Members a snippet may reach that are not the package's: Pest's, and those of the app code the
 * snippets imagine (a ticket model, a triage service).
 */
const README_FOREIGN_MEMBERS = ['askAHuman', 'body', 'escalate', 'queue', 'subject', 'toBe', 'triage'];

it('covers every topic issue #13 asks for, each under its own heading', function (string $heading): void {
    expect(Readme::load()->text)->toMatch('/^#{2,3} '.preg_quote($heading, '/').'$/m');
})->with([
    'Requirements',
    'Installation',
    'Defining enums',
    'Asking questions',
    'Reading answers',
    'Choosing thresholds',
    'Abstention',
    'Batch calls',
    'Connections',
    'Testing with the fake',
    'Events',
    'Caching',
    'Troubleshooting',
]);

it('links every runnable example', function (string $name): void {
    expect(Readme::load()->text)->toContain("examples/{$name}.php");
})->with(Example::names());

it('has PHP snippets that all parse', function (): void {
    $files = Readme::load()->phpFiles();

    expect($files)->not->toBeEmpty();

    foreach ($files as $i => $php) {
        try {
            token_get_all($php, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException("README PHP snippet #{$i} does not parse: {$error->getMessage()}\n{$php}", previous: $error);
        }
    }
});

it('imports only package classes that exist', function (): void {
    foreach (Readme::load()->phpFiles() as $php) {
        foreach (Readme::imports($php) as $class) {
            if (str_starts_with($class, Readme::PACKAGE_NAMESPACE)) {
                expect(class_exists($class) || interface_exists($class) || enum_exists($class))->toBeTrue("README imports {$class}, which does not exist.");
            }
        }
    }
});

it('calls only static members the package classes have', function (): void {
    // The facade forwards to the connection resolver, or to the fake once fake() swapped it in.
    $facade = [...get_class_methods(ConnectionResolver::class), ...get_class_methods(SystemOneFake::class), ...get_class_methods(SystemOne::class)];
    $checked = 0;

    foreach (Readme::load()->phpFiles() as $php) {
        // A snippet that leaves its imports out still means the package's class of that name.
        $imports = [...Readme::shortNames(), ...Readme::imports($php)];

        foreach (Readme::memberAccesses($php)['static'] as ['class' => $class, 'member' => $member]) {
            $class = $imports[$class] ?? $class;

            if (! str_starts_with($class, Readme::PACKAGE_NAMESPACE)) {
                continue;
            }

            $exists = $class === SystemOne::class
                ? in_array($member, $facade, true)
                : method_exists($class, $member) || defined("{$class}::{$member}");
            expect($exists)->toBeTrue("README uses {$class}::{$member}, which does not exist.");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(10);
});

it('reaches only methods and properties the package has', function (): void {
    $known = [...Readme::publicMembers(), ...README_FOREIGN_MEMBERS];

    foreach (Readme::load()->phpFiles() as $php) {
        foreach (Readme::memberAccesses($php)['instance'] as $member) {
            expect(in_array($member, $known, true))->toBeTrue("README uses ->{$member}, which no package class has.\n{$php}");
        }
    }
});

it('defines the same enums as the runnable examples', function (string $enum): void {
    $example = (string) file_get_contents(Example::directory()."/Enums/{$enum}.php");
    // The README shows them in an app's namespace; the examples have their own.
    $expected = str_replace('namespace Examples\Enums;', 'namespace App\Enums;', $example);

    expect(Readme::load()->blocks('php'))->toContain($expected);
})->with(['Department', 'Urgency']);

it('names only env vars that config/system-one.php or the Compose file reads', function (): void {
    $sources = file_get_contents(Readme::root().'/config/system-one.php')
        .file_get_contents(Readme::root().'/docker/compose.yaml')
        .file_get_contents(Readme::root().'/docker/compose.cuda.yaml')
        .file_get_contents(Readme::root().'/bin/laya-up')
        .file_get_contents(Readme::root().'/bin/lib/laya-compose.sh');
    preg_match_all('/\b((?:SYSTEM_ONE|LAYA)_[A-Z_]+)\b/', Readme::load()->text, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $name) {
        expect(preg_match("/\\b{$name}\\b/", $sources))->toBe(1, "README names {$name}, which nothing reads.");
    }
});

it('names only artisan commands and options that exist', function (): void {
    /** @var array<string, Command> $commands */
    $commands = [];

    foreach ([new InstallCommand, new HealthCommand, new TryCommand] as $command) {
        $commands[(string) $command->getName()] = $command;
    }

    preg_match_all('/\b(system-one:[a-z]+)((?:[ \t]+(?:--[a-z-]+(?:=\S*)?|"[^"\n]*"|\S+))*)/', Readme::load()->text, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $name, $arguments]) {
        expect($commands)->toHaveKey($name, message: "README names the command {$name}, which does not exist.");
        preg_match_all('/(?<=\s)--([a-z-]+)/', $arguments, $options);

        foreach ($options[1] as $option) {
            if (in_array($option, ['no-interaction', 'help'], true)) {
                continue;
            }

            expect($commands[$name]->getDefinition()->hasOption($option))->toBeTrue("README gives {$name} the option --{$option}, which it does not have.");
        }
    }
});

it('names only composer scripts that exist', function (): void {
    /** @var array{scripts: array<string, mixed>} $composer */
    $composer = json_decode((string) file_get_contents(Readme::root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    preg_match_all('/\bcomposer ([a-z]+(?::[a-z]+)?)\b/', Readme::load()->text, $matches);
    $builtIn = ['require', 'install', 'update', 'config'];

    foreach (array_unique($matches[1]) as $script) {
        if (! in_array($script, $builtIn, true)) {
            expect($composer['scripts'])->toHaveKey($script, message: "README runs composer {$script}, which is not a script.");
        }
    }
});
