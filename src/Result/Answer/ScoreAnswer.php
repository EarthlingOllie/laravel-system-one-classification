<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Probability;

use function array_map;
use function max;
use function min;

/**
 * The answer to a score question: the most likely level, typed as the question's enum, and
 * the expected level as a decimal.
 *
 * `expected` is on the scale of the backing values, so it compares with them directly
 * (`$answer->expected >= Urgency::High->value`) and can fall between two levels. Threshold on
 * `answerConfidence` (the probability of the reported answer), which compares across models.
 * `confidence` is the model's own raw value, tagged with its profile.
 *
 * @template TEnum of BackedEnum
 */
final readonly class ScoreAnswer implements Answer
{
    /**
     * @param  TEnum  $value  the most probable level
     * @param  float  $expected  the expected level, between the lowest and highest backing value
     * @param  Distribution<TEnum>  $distribution
     *
     * @throws InvalidProbability when answerConfidence is outside [0, 1]
     * @throws InvalidDistribution when the value is not one of the distribution's cases, or
     *                             expected lies outside its levels
     */
    public function __construct(
        public BackedEnum $value,
        public float $expected,
        public float $answerConfidence,
        public Confidence $confidence,
        public Distribution $distribution,
    ) {
        Probability::check('The answer confidence', $answerConfidence);
        $distribution->probabilityOf($value);

        $levels = array_map(static fn (BackedEnum $case): int|string => $case->value, $distribution->cases());
        // The value is one of the levels; passing it too keeps the lists non-empty for PHPStan.
        $lowest = min($value->value, ...$levels);
        $highest = max($value->value, ...$levels);

        // Written as "not inside" so that NAN, which fails every comparison, is refused too.
        if (! ($expected >= $lowest && $expected <= $highest)) {
            throw InvalidDistribution::expectedOutsideLevels($expected, $value::class, $lowest, $highest);
        }
    }

    /**
     * @param  TEnum  $case
     */
    public function probabilityOf(BackedEnum $case): float
    {
        return $this->distribution->probabilityOf($case);
    }

    /**
     * Whether answerConfidence reaches the threshold.
     *
     * @throws InvalidProbability when the threshold is outside [0, 1]
     */
    public function isConfident(float $threshold): bool
    {
        return $this->answerConfidence >= Probability::check('The threshold', $threshold);
    }
}
