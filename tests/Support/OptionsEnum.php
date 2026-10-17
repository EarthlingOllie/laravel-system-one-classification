<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use BackedEnum;

use function array_map;
use function class_exists;
use function implode;
use function range;

/**
 * String-backed enums with a given number of cases, for testing option limits at their
 * boundaries (99, 100, 101, 254, ...) without a fixture file of hundreds of cases each.
 *
 * Each is declared once, on first use, as Tests\Support\Generated\Options{count} with cases
 * O0, O1, ... backed by 'option_0', 'option_1', ...
 */
final readonly class OptionsEnum
{
    /**
     * @param  positive-int  $count
     * @return class-string<BackedEnum>
     */
    public static function withCases(int $count): string
    {
        $name = "Options{$count}";
        /** @var class-string<BackedEnum> $class */
        $class = __NAMESPACE__."\\Generated\\{$name}";

        if (! class_exists($class)) {
            $cases = implode(' ', array_map(
                static fn (int $i): string => "case O{$i} = 'option_{$i}';",
                range(0, $count - 1),
            ));

            // The enum's whole source is built here from integers, never from input.
            eval('namespace '.__NAMESPACE__."\\Generated; enum {$name}: string { {$cases} }");
        }

        return $class;
    }
}
