<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;

use function array_is_list;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * Maps a Decision to the System One wire format (`POST /v1/systemone`, shared by laya-serve
 * and Jev) and a response back to a Result.
 *
 * Each question type has one encode and one decode arm below; new types add an arm to both.
 */
final readonly class SystemOneCodec
{
    public const string PATH = '/v1/systemone';

    /**
     * @return array{state: string, questions: array<string, array<string, mixed>>}
     *
     * @throws InvalidDecision
     */
    public function encode(Decision $decision): array
    {
        $questions = [];

        foreach ($decision->questions() as $question) {
            $questions[$question->key] = $this->encodeQuestion($question);
        }

        if ($questions === []) {
            throw InvalidDecision::noQuestions();
        }

        return ['state' => $decision->context->text, 'questions' => $questions];
    }

    /**
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines
     */
    public function decode(Decision $decision, Response $response): Result
    {
        $body = json_decode($response->body, true);
        $status = $response->status;

        if ($status >= 500) {
            throw HostUnavailable::serverError($status, self::detail($body));
        }

        if ($status >= 400) {
            throw RejectedByModel::withStatus($status, self::detail($body));
        }

        if ($status < 200 || $status >= 300) {
            throw MalformedResponse::because("Unexpected HTTP status {$status}.", $response->body);
        }

        if (! is_array($body) || array_is_list($body)) {
            throw MalformedResponse::because('The response is not a JSON object.', $response->body);
        }

        $answers = $body['answers'] ?? null;

        if (! is_array($answers) || array_is_list($answers)) {
            throw MalformedResponse::because('The response has no answers object.', $response->body);
        }

        $decoded = [];

        foreach ($decision->questions() as $question) {
            $answer = $answers[$question->key] ?? throw MalformedResponse::because("The response has no answer for '{$question->key}'.", $response->body);

            if (! is_array($answer)) {
                throw MalformedResponse::because("The answer for '{$question->key}' is not an object.", $response->body);
            }

            $decoded[$question->key] = $this->decodeAnswer($question, $answer, $response->body);
        }

        return new Result($decoded);
    }

    /**
     * @param  QuestionHandle<Answer>  $question
     * @return array<string, mixed>
     */
    private function encodeQuestion(QuestionHandle $question): array
    {
        return match (true) {
            $question instanceof YesNoQuestion => ['type' => 'noul', 'instructions' => $question->instructions],
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * @param  QuestionHandle<Answer>  $question
     * @param  array<mixed>  $answer
     */
    private function decodeAnswer(QuestionHandle $question, array $answer, string $body): Answer
    {
        return match (true) {
            $question instanceof YesNoQuestion => self::decodeYesNo($question->key, $answer, $body),
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * @param  array<mixed>  $answer
     */
    private static function decodeYesNo(string $key, array $answer, string $body): YesNoAnswer
    {
        $type = $answer['type'] ?? null;

        if ($type !== 'noul') {
            throw MalformedResponse::because("The answer for '{$key}' should have type 'noul', got ".json_encode($type, JSON_THROW_ON_ERROR).'.', $body);
        }

        $probability = $answer['noul'] ?? null;

        if (! is_int($probability) && ! is_float($probability)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'noul' probability.", $body);
        }

        if ($probability < 0 || $probability > 1) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'noul' probability outside [0, 1]: {$probability}.", $body);
        }

        return new YesNoAnswer((float) $probability);
    }

    /** The `{"detail": "..."}` message laya-serve and Jev put on every error, if present. */
    private static function detail(mixed $body): ?string
    {
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;

        return is_string($detail) ? $detail : null;
    }
}
