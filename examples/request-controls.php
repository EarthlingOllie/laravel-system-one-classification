<?php

declare(strict_types=1);

/*
 * Request controls and result metadata: structured fields as the context, the language,
 * token budgets and a pinned checkpoint, then what the server reports about the call.
 *
 *     php examples/request-controls.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;

require __DIR__.'/bootstrap.php';

$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

$decision = Decision::about(Context::fields([
    'subject' => 'Charged twice',
    'body' => 'We were billed twice for March. Refund it or we will cancel our plan.',
    'seats' => 3,
]))
    ->ask($churn)
    ->withModel('english')      // pin a checkpoint; the connection's profile checks the name
    ->withLanguage('en')        // `lang`: the context's language
    ->withLanguageHint('en')    // `lang_guess`: a weaker hint the server may overrule
    ->withMaxLength(256)        // `max_len`: token budget for the whole input
    ->withHeadMaxLength(32);    // `head_max_len`: token budget per question

$result = SystemOne::decide($decision);
$meta = $result->meta;

printf("model: %s\n", $meta->model ?? 'not reported');
printf("checkpoint: %s\n", $meta->checkpoint ?? 'not reported');
printf("routing reason: %s\n", $meta->routingReason ?? 'not reported');
printf("input tokens: %s\n", $meta->inputTokens ?? 'not reported');
printf("output tokens: %s\n", $meta->outputTokens ?? 'not reported');
printf("latency: %d ms\n", $meta->latencyMs ?? 0);
printf("churn: %.4f\n", $result->get($churn)->probability);
