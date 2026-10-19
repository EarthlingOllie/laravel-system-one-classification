<?php

declare(strict_types=1);

/*
 * Named connections: `local` (the default), `laya-hosted` and `jev`. The same decision goes to
 * whichever you name. The remote ones send your text to a third party, so they are used only
 * when you name them and give them a key. This example sends nothing to a remote host.
 *
 *     php examples/connections.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;

require __DIR__.'/bootstrap.php';

$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
$decision = Decision::about(Context::text('We will cancel our plan unless you refund us.'))->ask($churn);
$manager = app(SystemOneManager::class);

printf("default connection: %s\n", $manager->defaultConnection());

// SystemOne::decide() uses the default; connection('local') names it.
$answer = SystemOne::connection('local')->decide($decision)->get($churn);
printf("local (remote: %s): churn %.4f\n", $manager->configuration('local')->remote ? 'yes' : 'no', $answer->probability);

foreach (['laya-hosted', 'jev'] as $name) {
    $remote = $manager->configuration($name)->remote ? 'yes' : 'no';

    try {
        // A remote connection without an api_key refuses here, before anything is sent.
        SystemOne::connection($name);
        printf("%s (remote: %s): has a key, so it would send to %s\n", $name, $remote, $manager->displayUrl($name));
    } catch (InvalidConfiguration $refused) {
        printf("%s (remote: %s): refused before sending: %s\n", $name, $remote, $refused->getMessage());
    }
}
