<?php

declare(strict_types=1);

/*
 * A batch: the same questions about many contexts, through the batch endpoint. Contexts are
 * split into as many requests as the server's limits need (64 states, 2 MiB a request), and
 * the results come back in input order.
 *
 *     php examples/batch.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use Examples\Enums\Department;

require __DIR__.'/bootstrap.php';

$department = Question::choice('department', Department::class, 'Which department should handle this?');
$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

$tickets = [
    'We were billed twice for March. Refund it or we will cancel our plan.',
    'The app crashes every time I open the settings page.',
    'Just wanted to say the new dashboard looks great.',
];

// The decision carries the questions and any request controls; its own context is not sent.
$decision = Decision::about(Context::text($tickets[0]))->ask($department, $churn);

$results = SystemOne::decideMany(array_map(Context::text(...), $tickets), $decision);

printf("%d results, %s input tokens\n", count($results), $results->usage->inputTokens ?? 'unreported');

foreach ($results as $i => $result) {
    printf("#%d department %s, churn %.4f\n", $i, $result->get($department)->value->value, $result->get($churn)->probability);
}
