<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;

it('returns the answer to a question it holds, read by the question handle', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $refund = Question::yesNo('refund', 'Does the customer ask for a refund?');
    $churnAnswer = new YesNoAnswer(0.91);
    $refundAnswer = new YesNoAnswer(0.12);

    $result = new Result(['churn' => $churnAnswer, 'refund' => $refundAnswer]);

    expect($result->get($churn))->toBe($churnAnswer)
        ->and($result->get($refund))->toBe($refundAnswer);
});

it('reads an answer with an equal handle built separately', function (): void {
    $answer = new YesNoAnswer(0.91);
    $result = new Result(['churn' => $answer]);

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?')))->toBe($answer);
});

it('throws a clear exception for a question that was not part of the decision', function (): void {
    $result = new Result(['churn' => new YesNoAnswer(0.91)]);

    $result->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "The result has no answer for the question 'refund': it was not part of the decision. Asked: 'churn'.");

it('names no questions when the result is empty', function (): void {
    (new Result([]))->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "The result has no answer for the question 'refund': it was not part of the decision. Asked: none.");

it('refuses a handle whose answer type differs from the answer held under its key', function (): void {
    $other = new class implements Answer {};
    $handle = new class($other::class) implements QuestionHandle
    {
        public string $key = 'churn';

        public string $instructions = 'Pick one.';

        /** @param class-string<Answer> $type */
        public function __construct(private string $type) {}

        public function answerType(): string
        {
            return $this->type;
        }
    };

    expect(fn () => (new Result(['churn' => new YesNoAnswer(0.91)]))->get($handle))
        ->toThrow(UnknownQuestion::class, "The answer to 'churn' is a ".YesNoAnswer::class.', not the '.$other::class.' this question expects: it was not part of the decision.');
});

it('lists every asked key in the message', function (): void {
    (new Result(['churn' => new YesNoAnswer(0.9), 'angry' => new YesNoAnswer(0.1)]))
        ->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "Asked: 'churn', 'angry'.");
