<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use EarthlingOllie\SystemOneClassification\Result\Verdict;
use InvalidArgumentException;

/** The answer to a yes/no question: the probability that the statement is true. */
final readonly class YesNoAnswer implements Answer
{
    public function __construct(public float $probability)
    {
        if (! self::isUnitInterval($probability)) {
            throw new InvalidArgumentException("A probability must be between 0 and 1, got {$probability}.");
        }
    }

    /** Whether the probability reaches the threshold. */
    public function isYes(float $threshold = 0.5): bool
    {
        self::checkThreshold('The threshold', $threshold);

        return $this->probability >= $threshold;
    }

    /**
     * Yes at or above `yes`, No at or below `no`, Unsure in between. When both thresholds are
     * equal, a probability that reaches them is Yes.
     */
    public function verdict(float $yes, float $no): Verdict
    {
        self::checkThreshold('The yes threshold', $yes);
        self::checkThreshold('The no threshold', $no);

        if ($no > $yes) {
            throw new InvalidArgumentException("The no threshold ({$no}) must not be above the yes threshold ({$yes}).");
        }

        return match (true) {
            $this->probability >= $yes => Verdict::Yes,
            $this->probability <= $no => Verdict::No,
            default => Verdict::Unsure,
        };
    }

    private static function checkThreshold(string $name, float $threshold): void
    {
        if (! self::isUnitInterval($threshold)) {
            throw new InvalidArgumentException("{$name} must be between 0 and 1, got {$threshold}.");
        }
    }

    private static function isUnitInterval(float $value): bool
    {
        return $value >= 0.0 && $value <= 1.0;
    }
}
