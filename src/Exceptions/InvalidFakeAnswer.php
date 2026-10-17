<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use BackedEnum;
use InvalidArgumentException;

/** A scripted answer for SystemOne::fake() that no model could give. */
final class InvalidFakeAnswer extends InvalidArgumentException implements SystemOneException
{
    public static function confidenceBelowShare(string $key, float $confidence, int $options): self
    {
        return new self("The scripted answer to '{$key}' cannot have a confidence of {$confidence}: with {$options} options, the chosen one is at least 1/{$options} likely.");
    }

    public static function expectedOutOfReach(string $key, BackedEnum $case, float $expected, float $lowest, float $highest): self
    {
        return new self("The scripted answer to '{$key}' cannot have an expected level of {$expected} with {$case->name} as its most likely level: that level allows {$lowest} to {$highest}.");
    }
}
