<?php

declare(strict_types=1);

/*
 * Events and the result cache: listen for DecisionMade and DecisionFailed, and answer a
 * repeated decision from a Laravel cache store instead of the server.
 *
 *     php examples/events-and-caching.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use Illuminate\Support\Facades\Event;

require __DIR__.'/bootstrap.php';

// Usually set in .env: SYSTEM_ONE_CACHE=true, SYSTEM_ONE_CACHE_STORE=..., SYSTEM_ONE_CACHE_TTL=...
config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);

Event::listen(function (DecisionMade $event): void {
    printf(
        "DecisionMade: %s, questions %s, cached %s, context %s\n",
        $event->connection,
        implode(', ', $event->questionKeys),
        $event->cached ? 'yes' : 'no',
        // Only with SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT=true: the text may hold personal data.
        $event->context === null ? 'not included' : 'included',
    );
});

Event::listen(function (DecisionFailed $event): void {
    printf("DecisionFailed: %s, questions %s, %s\n", $event->connection, implode(', ', $event->questionKeys), (new ReflectionClass($event->exception))->getShortName());
});

$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
$decision = Decision::about(Context::text('We will cancel our plan unless you refund us.'))->ask($churn);

// The first is sent; the second, identical one is answered from the cache. Each reads the
// server's /health first, so a new checkpoint revision is never served a stale answer.
$first = SystemOne::decide($decision);
$second = SystemOne::decide($decision);
printf("same answer from the cache: %s\n", $first->get($churn)->probability === $second->get($churn)->probability ? 'yes' : 'no');

try {
    // A checkpoint the Laya profile does not know: refused before anything is sent.
    SystemOne::decide($decision->withModel('no-such-checkpoint'));
} catch (InvalidDecision) {
    // DecisionFailed was dispatched first; the exception still reaches the caller.
}
