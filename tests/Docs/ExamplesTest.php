<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\Example;

/*
 * The runnable examples in examples/, so the docs cannot drift from the code: the one that
 * needs no server runs here, in the main suite; the Integration suite runs the rest against the
 * Laya container (tests/Integration/ExamplesTest.php).
 */

function closedPort(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    assert($socket !== false);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return "http://{$address}";
}

it('runs every example in CI: each one is either the fake example or has expectations for the server run', function (): void {
    $covered = [...array_keys(Example::SERVER_EXPECTATIONS), Example::WITHOUT_SERVER];
    sort($covered);

    expect(Example::names())->toBe($covered);
});

it('runs the fake example with no model server, every assertion of it passing', function (): void {
    // Nothing listened at this port a moment ago, and the scripted values asserted below can only
    // come from the fake. Isolated from the shell's SYSTEM_ONE_*, so it runs on the defaults.
    $run = Example::run(Example::WITHOUT_SERVER, ['SYSTEM_ONE_URL' => closedPort()], isolated: true);

    expect($run->exitCode)->toBe(0, $run->transcript())
        ->and($run->errors)->toBe('', $run->transcript())
        ->and($run->output)
        ->toMatch('/^triage: department Billing \(answerConfidence 0\.92\), urgency High \(expected 1\.7\), churn 0\.91 -> escalate$/m')
        ->toMatch('/^unscripted: department Billing \(answerConfidence 1\), urgency Low \(expected 0\), churn 0 -> queue$/m')
        ->toMatch('/^strict: UnscriptedQuestion: .+$/m')
        ->toMatch('/^abstained: department Billing, Abstained at 0\.8 -> send to a human$/m')
        ->toMatch('/^jev, with no key: second opinion churn 0\.91$/m')
        ->toMatch('/^All assertions passed\.$/m');
});
