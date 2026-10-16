<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_keys;

/** The answers to one decision, read with the question handles that asked them. */
final readonly class Result
{
    /**
     * @param  array<string, Answer>  $answers  keyed by question key
     */
    public function __construct(private array $answers) {}

    /**
     * @template TAnswer of Answer
     *
     * @param  QuestionHandle<TAnswer>  $question
     * @return TAnswer
     *
     * @throws UnknownQuestion when the question was not part of the decision
     */
    public function get(QuestionHandle $question): Answer
    {
        $answer = $this->answers[$question->key] ?? throw UnknownQuestion::notAsked($question->key, array_keys($this->answers));
        $type = $question->answerType();

        if (! $answer instanceof $type) {
            throw UnknownQuestion::otherType($question->key, $answer::class, $type);
        }

        return $answer;
    }
}
