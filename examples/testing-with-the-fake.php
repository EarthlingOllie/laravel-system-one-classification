<?php

declare(strict_types=1);

/*
 * The testing fake: SystemOne::fake() answers from scripted answers instead of a model and
 * records every decision for assertions. This example needs no server; in an app, the same
 * calls go in a Pest or PHPUnit test.
 *
 *     php examples/testing-with-the-fake.php
 */

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\UnscriptedQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use Examples\Enums\Department;
use Examples\Enums\Urgency;

require __DIR__.'/bootstrap.php';

/** App code under test. It injects ConnectionResolver (not SystemOneManager), so the fake can stand in. */
final readonly class TicketTriage
{
    public function __construct(private ConnectionResolver $systemOne) {}

    /** @return ChoiceQuestion<Department> */
    public static function department(): ChoiceQuestion
    {
        return Question::choice('department', Department::class, 'Which department should handle this?');
    }

    /** @return ScoreQuestion<Urgency> */
    public static function urgency(): ScoreQuestion
    {
        return Question::score('urgency', Urgency::class, 'How urgent is this?');
    }

    public static function churn(): YesNoQuestion
    {
        return Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    }

    public function triage(string $ticket): string
    {
        $result = $this->systemOne->decide(
            Decision::about(Context::text($ticket))->ask(self::department(), self::urgency(), self::churn()),
        );
        $department = $result->get(self::department());
        $urgency = $result->get(self::urgency());
        $churn = $result->get(self::churn());
        $escalate = $churn->isYes(0.8) || $urgency->expected >= Urgency::High->value;

        return sprintf(
            'department %s (answerConfidence %s), urgency %s (expected %s), churn %s -> %s',
            $department->value->name,
            round($department->answerConfidence, 2),
            $urgency->value->name,
            round($urgency->expected, 2),
            round($churn->probability, 2),
            $escalate ? 'escalate' : 'queue',
        );
    }
}

$ticket = 'We were billed twice for March. Refund it today or we will cancel our plan.';

// Scripted answers. Handles compare by value, so the ones built here match the app's.
$fake = SystemOne::fake([
    FakeAnswer::choice(TicketTriage::department(), Department::Billing, confidence: 0.92),
    FakeAnswer::score(TicketTriage::urgency(), Urgency::High, expected: 1.7),
    FakeAnswer::yesNo(TicketTriage::churn(), 0.91),
]);

echo 'triage: '.app(TicketTriage::class)->triage($ticket)."\n";

$fake->assertDecidedTimes(1);
$fake->assertAsked(TicketTriage::department());
$fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'local' && $decision->context->state === $ticket);

// A question with no script gets a certain default: the first option, the lowest level, no.
SystemOne::fake();
echo 'unscripted: '.app(TicketTriage::class)->triage($ticket)."\n";

// strict() turns a missing script into an exception instead.
SystemOne::fake([FakeAnswer::yesNo(TicketTriage::churn(), 0.91)])->strict();

try {
    app(TicketTriage::class)->triage($ticket);
} catch (UnscriptedQuestion $unscripted) {
    echo 'strict: UnscriptedQuestion: '.$unscripted->getMessage()."\n";
}

// An abstained answer keeps its value and reads as Abstained at the decision's threshold.
SystemOne::fake([FakeAnswer::abstained(FakeAnswer::choice(TicketTriage::department(), Department::Billing, confidence: 0.9))]);
$answer = SystemOne::decide(Decision::about(Context::text($ticket))->ask(TicketTriage::department())->withMinConfidence(0.8))
    ->get(TicketTriage::department());
printf("abstained: department %s, %s at %s -> %s\n", $answer->value->name, $answer->abstention->name, $answer->abstentionThreshold, $answer->abstained ? 'send to a human' : 'act on it');

// Named connections work under the fake, remote ones without a key: nothing is sent.
$fake = SystemOne::fake([FakeAnswer::yesNo(TicketTriage::churn(), 0.91)]);
$answer = SystemOne::connection('jev')->decide(Decision::about(Context::text($ticket))->ask(TicketTriage::churn()))->get(TicketTriage::churn());
printf("jev, with no key: second opinion churn %s\n", $answer->probability);
$fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'jev');

echo "All assertions passed.\n";
