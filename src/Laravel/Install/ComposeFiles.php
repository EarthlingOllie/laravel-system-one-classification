<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Install;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_is_list;
use function array_map;
use function array_slice;
use function array_splice;
use function data_get;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;

/**
 * The Compose files `system-one:install` writes into an app, both built from the package's own
 * docker/compose.yaml so they cannot drift from it:
 *
 * - standalone(): that file, with its build context pointed at the installed package;
 * - addToSail(): its `laya` service, added to the app's Sail file as new lines only, so every
 *   existing line (comments and formatting included) stays as it was.
 */
final readonly class ComposeFiles
{
    public const string SAIL_VOLUME = 'sail-laya';

    private const string CONTEXT_LINE = 'context: ./laya';

    /** Where the package compose file's header comment ends. */
    private const string FIRST_KEY = "\nname: ";

    private const string HF_CACHE = '/home/laya/.cache/huggingface';

    public function __construct(private string $packageDir) {}

    /**
     * The contents of compose.system-one.yaml for an app at $appBasePath.
     *
     * @throws InvalidConfiguration when the package compose file no longer has the expected build context
     */
    public function standalone(string $appBasePath): string
    {
        $source = $this->packageCompose();

        if (substr_count($source, self::CONTEXT_LINE) !== 1) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), "has no '".self::CONTEXT_LINE."' line to point at the installed package");
        }

        if (substr_count($source, self::FIRST_KEY) !== 1) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), "has no single top-level 'name:' line ending its header");
        }

        // Drop the package's own header (it talks about `composer laya:up`, which only exists in the package repo).
        $body = 'name: '.implode('', array_slice(explode(self::FIRST_KEY, $source), 1));

        return <<<'YAML'
            # Local Laya server for this app, written by `php artisan system-one:install`.
            #
            #   docker compose -f compose.system-one.yaml up -d --wait   # start; waits until the checkpoint is loaded
            #   docker compose -f compose.system-one.yaml down           # stop; the weights stay in the volume
            #
            # The first start builds the image and downloads the english checkpoint (~0.85 GB), which
            # takes a while. Every LAYA_* setting below can be overridden from the environment.

            YAML.str_replace(self::CONTEXT_LINE, 'context: '.$this->contextFor($appBasePath), $body);
    }

    /**
     * The Sail Compose file with a `laya` service and its model-cache volume added, or null when
     * both are already there. An existing `laya` service is kept as it is.
     *
     * @throws InvalidConfiguration when the file is not YAML or not laid out in a way that can be added to safely
     */
    public function addToSail(string $compose, string $appBasePath, string $file = 'The Sail Compose file'): ?string
    {
        $parsed = $this->parse($compose, $file);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;

        if (! is_array($services) || ($services !== [] && array_is_list($services))) {
            throw InvalidConfiguration::unusableCompose($file, 'has no services section');
        }

        $volumes = $parsed['volumes'] ?? [];

        if (! is_array($volumes)) {
            throw InvalidConfiguration::unusableCompose($file, "'volumes' is not a map");
        }

        $updated = $compose;

        if (! isset($services['laya'])) {
            $networks = $parsed['networks'] ?? null;
            $updated = $this->insertUnder($updated, $file, 'services', ['laya' => $this->sailService(is_array($networks) && isset($networks['sail']), $appBasePath)]);
        }

        if (! isset($volumes[self::SAIL_VOLUME])) {
            $updated = isset($parsed['volumes'])
                ? $this->insertUnder($updated, $file, 'volumes', [self::SAIL_VOLUME => ['driver' => 'local']])
                : rtrim($updated, "\n")."\nvolumes:\n".$this->block([self::SAIL_VOLUME => ['driver' => 'local']], self::indentOf($updated));
        }

        return $updated === $compose ? null : $updated;
    }

    /** @return array<mixed> the package's `laya` service, adapted to live in a Sail file */
    private function sailService(bool $sailNetwork, string $appBasePath): array
    {
        $parsed = $this->parse($this->packageCompose(), $this->composePath());
        $service = data_get($parsed, 'services.laya');

        if (! is_array($service)) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), 'has no laya service');
        }

        $service['build'] = ['context' => $this->contextFor($appBasePath)];
        $service['volumes'] = [self::SAIL_VOLUME.':'.self::HF_CACHE];

        if ($sailNetwork) {
            $service['networks'] = ['sail'];
        }

        return $service;
    }

    /**
     * Inserts $entries as the first children of the top-level block-style `$section:` line.
     *
     * @param  array<mixed>  $entries
     *
     * @throws InvalidConfiguration when there is no such line
     */
    private function insertUnder(string $compose, string $file, string $section, array $entries): string
    {
        $lines = explode("\n", $compose);

        foreach ($lines as $i => $line) {
            if (preg_match('/^'.$section.':[ \t]*(?:#.*)?$/', $line) === 1) {
                array_splice($lines, $i + 1, 0, explode("\n", rtrim($this->block($entries, self::indentOf($compose)), "\n")));

                return implode("\n", $lines);
            }
        }

        throw InvalidConfiguration::unusableCompose($file, "has no block-style '{$section}:' line to add to");
    }

    /** @param array<mixed> $entries */
    private function block(array $entries, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $lines = explode("\n", rtrim(Yaml::dump($entries, PHP_INT_MAX, $indent), "\n"));

        return implode("\n", array_map(fn (string $line): string => $pad.$line, $lines))."\n";
    }

    /**
     * The indentation of the file's first indented line that is not a comment.
     *
     * Only called on a file with a block-style map of services, which always has one.
     */
    private static function indentOf(string $compose): int
    {
        preg_match_all('/^( +)[^ #\n]/m', $compose, $matches);

        return strlen(implode('', array_slice($matches[1], 0, 1)));
    }

    /** The package's docker/laya directory as an app at $appBasePath should name it. */
    private function contextFor(string $appBasePath): string
    {
        $dir = $this->packageDir.'/docker/laya';
        $base = rtrim($appBasePath, '/').'/';

        return str_starts_with($dir, $base) ? './'.substr($dir, strlen($base)) : $dir;
    }

    private function parse(string $yaml, string $what): mixed
    {
        try {
            return Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw InvalidConfiguration::unusableCompose($what, 'is not valid YAML: '.$exception->getMessage());
        }
    }

    private function packageCompose(): string
    {
        return (string) file_get_contents($this->composePath());
    }

    private function composePath(): string
    {
        return $this->packageDir.'/docker/compose.yaml';
    }
}
