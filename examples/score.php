<?php

declare(strict_types=1);

/*
 * A score question: ordinal levels from an int-backed enum, lowest backing value first.
 *
 *     php examples/score.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use Examples\Enums\Urgency;

require __DIR__.'/bootstrap.php';

$urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');

$decision = Decision::about(Context::text(
    'Our checkout has been down for an hour and no customer can pay. This is blocking us.',
))->ask($urgency);

$answer = SystemOne::decide($decision)->get($urgency);

// The most likely level, as an Urgency.
printf("urgency: %s (level %d)\n", $answer->value->name, $answer->value->value);
// The mean backing value under the distribution: it can fall between two levels.
printf("expected: %.2f\n", $answer->expected);
printf("answerConfidence: %.4f\n", $answer->answerConfidence);

$entries = array_map(
    static fn (array $entry): string => sprintf('%d %.4f', $entry['case']->value, $entry['probability']),
    $answer->distribution->entries(),
);
printf("distribution: %s\n", implode(', ', $entries));
// expected compares with the backing values directly.
printf("expected at least High: %s\n", $answer->expected >= Urgency::High->value ? 'yes' : 'no');
