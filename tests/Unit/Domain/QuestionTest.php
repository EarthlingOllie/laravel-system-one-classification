<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;

it('builds a yes/no question with its explicit key and instructions', function (): void {
    $question = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    expect($question)->toBeInstanceOf(YesNoQuestion::class)
        ->and($question->key)->toBe('churn')
        ->and($question->instructions)->toBe('Does the customer threaten to cancel?')
        ->and($question->answerType())->toBe(YesNoAnswer::class);
});

it('rejects an empty key', function (string $key): void {
    Question::yesNo($key, 'Does the customer threaten to cancel?');
})->with(['empty' => '', 'blank' => '  '])->throws(InvalidDecision::class, 'A question key must not be empty.');

it('rejects empty instructions', function (string $instructions): void {
    Question::yesNo('churn', $instructions);
})->with(['empty' => '', 'blank' => " \n"])->throws(InvalidDecision::class, "Question 'churn' needs instructions.");

it('holds the context text as given', function (): void {
    expect(Context::text('  We were billed twice.  ')->text)->toBe('  We were billed twice.  ');
});
