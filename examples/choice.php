<?php

declare(strict_types=1);

/*
 * A choice question: the model picks one case of a backed enum, described by #[Describe].
 *
 *     php examples/choice.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use Examples\Enums\Department;

require __DIR__.'/bootstrap.php';

$department = Question::choice('department', Department::class, 'Which department should handle this?');

$decision = Decision::about(Context::text(
    'We were billed twice for March. Please refund the duplicate charge.',
))->ask($department);

$answer = SystemOne::decide($decision)->get($department);

// $answer->value is a Department, not a string.
printf("department: %s (Department::%s)\n", $answer->value->value, $answer->value->name);
// The probability of the reported answer: what to threshold on.
printf("answerConfidence: %.4f\n", $answer->answerConfidence);
// The model's own raw value, tagged with the profile that produced it.
printf("confidence: %.4f (profile %s)\n", $answer->confidence->value, $answer->confidence->profile);

$entries = array_map(
    static fn (array $entry): string => sprintf('%s %.4f', $entry['case']->value, $entry['probability']),
    $answer->distribution->entries(),
);
printf("distribution: %s\n", implode(', ', $entries));
printf("probabilityOf(Department::Other): %.4f\n", $answer->probabilityOf(Department::Other));
printf("isConfident(0.8): %s\n", $answer->isConfident(0.8) ? 'yes' : 'no');
