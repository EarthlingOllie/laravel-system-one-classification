<?php

declare(strict_types=1);

/*
 * Thresholds and abstention: let the server gate every answer at a minimum confidence, and
 * send what falls below it to a human. An abstained answer still holds the model's best guess.
 *
 *     php examples/thresholds-and-abstention.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use Examples\Enums\Department;
use Examples\Enums\Urgency;

require __DIR__.'/bootstrap.php';

$department = Question::choice('department', Department::class, 'Which department should handle this?');
$urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

$result = SystemOne::decide(
    Decision::about(Context::text('We were billed twice for March. Refund the duplicate charge today or we will cancel our plan.'))
        ->ask($department, $urgency, $churn)
        ->withMinConfidence(0.6),
);

// The server gates on the probability of the reported answer (answerConfidence). A yes/no
// answer's is the probability of the side it reports: max(p, 1 - p).
$yesNoConfidence = static fn (YesNoAnswer $answer): float => max($answer->probability, 1 - $answer->probability);

$answer = $result->get($department);
printf("department: %s, answerConfidence %.4f, %s at %s\n", $answer->value->value, $answer->answerConfidence, $answer->abstention->name, $answer->abstentionThreshold);

$answer = $result->get($urgency);
printf("urgency: %d, answerConfidence %.4f, %s at %s\n", $answer->value->value, $answer->answerConfidence, $answer->abstention->name, $answer->abstentionThreshold);

$answer = $result->get($churn);
printf("churn: %.4f, answerConfidence %.4f, %s at %s\n", $answer->probability, $yesNoConfidence($answer), $answer->abstention->name, $answer->abstentionThreshold);

// Text the model cannot be sure about, against a strict gate.
$answer = SystemOne::decide(
    Decision::about(Context::text('Hello, I have a question.'))->ask($churn)->withMinConfidence(0.99),
)->get($churn);
printf(
    "ambiguous churn: %.4f, answerConfidence %.4f, %s at %s -> %s\n",
    $answer->probability,
    $yesNoConfidence($answer),
    $answer->abstention->name,
    $answer->abstentionThreshold,
    $answer->abstained ? 'send to a human' : 'act on it',
);

// Without withMinConfidence() no gate runs on the server; threshold in PHP instead.
$answer = SystemOne::decide(
    Decision::about(Context::text('The app crashes every time I open the settings page.'))->ask($department),
)->get($department);
printf("without a server-side gate: %s, isConfident(0.8) %s\n", $answer->abstention->name, $answer->isConfident(0.8) ? 'yes' : 'no');
