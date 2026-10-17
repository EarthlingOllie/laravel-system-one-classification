<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Model;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

use function count;

/**
 * Checks a decision against a model profile's limits, so a decision the model would refuse
 * fails in PHP, naming the question and the limit, instead of with a 413 or 422 from the
 * server. SystemOneCodec::encode() runs it before encoding anything.
 */
final readonly class ProfileLimits
{
    /** @throws InvalidDecision naming the first question that breaks a limit, and the limit */
    public static function check(ModelProfile $profile, Decision $decision): void
    {
        foreach ($decision->questions() as $question) {
            if ($question instanceof ChoiceQuestion) {
                $options = count($question->options());

                if ($options > $profile->maxChoiceOptions) {
                    throw InvalidDecision::tooManyOptions($question->key, $options, $profile->maxChoiceOptions, $profile->name);
                }
            }
        }
    }
}
