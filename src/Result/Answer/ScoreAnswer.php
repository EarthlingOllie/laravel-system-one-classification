<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Probability;

use function array_reverse;
use function is_int;
use function krsort;

/**
 * The answer to a score question: the most likely level, typed as the question's enum, and
 * the expected level as a decimal.
 *
 * `expected` is the expected backing value under the distribution, so it compares with the
 * values directly (`$answer->expected >= Urgency::High->value`) and can fall between two
 * levels. Threshold on `answerConfidence` (the probability of the reported answer), which
 * compares across models. `confidence` is the model's own raw value, tagged with its profile.
 *
 * @template TEnum of BackedEnum
 */
final readonly class ScoreAnswer implements Answer
{
    /** The expected level: the mean backing value, weighted by the distribution. */
    public float $expected;

    /**
     * @param  TEnum  $value  the most probable level
     * @param  Distribution<TEnum>  $distribution  over an int-backed enum; the probabilities are
     *                                             normalised, as the server rounds each one
     *
     * @throws InvalidProbability when answerConfidence is outside [0, 1]
     * @throws InvalidDistribution when the value is not one of the distribution's cases, the
     *                             enum is not int-backed, or no level has any probability
     */
    public function __construct(
        public BackedEnum $value,
        public float $answerConfidence,
        public Confidence $confidence,
        public Distribution $distribution,
    ) {
        Probability::check('The answer confidence', $answerConfidence);
        $distribution->probabilityOf($value);

        $this->expected = self::expected($value::class, $distribution);
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

    /**
     * The mean backing value, written as the lowest value plus, for each step up to a higher
     * level, the probability of reaching it times the step's width:
     * E = v0 + P(>= v1)(v1 - v0) + P(>= v2)(v2 - v1) + ...
     *
     * The "at least" sums are added from the top down, so none exceeds the total they are
     * divided by, and each step adds between nothing and its full width: the result stays
     * within the lowest and highest value even with floating-point rounding.
     *
     * @param  Distribution<TEnum>  $distribution
     */
    private static function expected(string $enum, Distribution $distribution): float
    {
        $probabilities = [];

        foreach ($distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            if (! is_int($case->value)) {
                throw InvalidDistribution::notIntBacked($case::class);
            }

            $probabilities[$case->value] = $probability;
        }

        krsort($probabilities);
        $atLeast = [];
        $total = 0.0;

        foreach ($probabilities as $level => $probability) {
            $total += $probability;
            $atLeast[$level] = $total;
        }

        if ($total <= 0.0) {
            throw InvalidDistribution::noProbability($enum);
        }

        // From the lowest level up; below it, the "previous value" is 0, and the lowest
        // level's own step is all the probability (total / total = 1) times its value.
        $expected = 0.0;
        $previous = 0;

        foreach (array_reverse($atLeast, true) as $level => $mass) {
            $expected += $mass / $total * ($level - $previous);
            $previous = $level;
        }

        return $expected;
    }
}
