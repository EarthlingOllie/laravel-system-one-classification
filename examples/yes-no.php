<?php

declare(strict_types=1);

/*
 * A yes/no question: the probability that a statement about the text is true.
 *
 *     php examples/yes-no.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;

require __DIR__.'/bootstrap.php';

$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

$decision = Decision::about(Context::text(
    'We were billed twice for March. Refund the duplicate charge today or we will cancel our plan.',
))->ask($churn);

$answer = SystemOne::decide($decision)->get($churn);

printf("churn: probability %.4f\n", $answer->probability);
// isYes() draws the line at 0.5 unless you give it a threshold.
printf("isYes(): %s\n", $answer->isYes() ? 'yes' : 'no');
// verdict() leaves the middle band as Unsure, for a human to look at.
printf("verdict(yes: 0.8, no: 0.2): %s\n", $answer->verdict(yes: 0.8, no: 0.2)->name);
