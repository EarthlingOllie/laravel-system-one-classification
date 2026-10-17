<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Model\ModelProfile;
use EarthlingOllie\SystemOneClassification\Model\ProfileLimits;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use JsonException;
use stdClass;

use function array_column;
use function array_diff;
use function array_filter;
use function array_is_list;
use function array_keys;
use function array_map;
use function count;
use function get_object_vars;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;

/**
 * Maps a Decision to the System One wire format (`POST /v1/systemone`, shared by laya-serve
 * and Jev) and a response back to a Result.
 *
 * Each question type has one arm in wire(), which gives both its request and its decoder.
 */
final readonly class SystemOneCodec
{
    public const string PATH = '/v1/systemone';

    /** Where laya-serve reports its loaded checkpoints and their revisions. */
    public const string HEALTH_PATH = '/health';

    /**
     * @param  ModelProfile  $profile  the model behind the connection: encode() checks every
     *                                 decision against its limits, and every decoded
     *                                 Confidence is tagged with its name
     */
    public function __construct(private ModelProfile $profile = new LayaProfile) {}

    /**
     * @param  ?string  $model  the connection's default model, sent when the decision pins none
     *                          and held to the profile as a pinned one is
     * @return array{state: string|array<string, mixed>, model?: string, min_confidence?: float, lang?: string, lang_guess?: string, max_len?: int, head_max_len?: int, questions: array<string, array<string, mixed>>} choice criteria are a stdClass, so they always encode as a JSON object; `model` only when the decision pins one or the connection has a default, and a request control only when the decision sets it
     *
     * @throws InvalidDecision when the decision cannot be sent, or breaks a limit of the profile
     */
    public function encode(Decision $decision, ?string $model = null): array
    {
        if ($decision->model === null && $model !== null) {
            $decision = $decision->withModel($model);
        }

        ProfileLimits::check($this->profile, $decision);

        $questions = [];

        foreach ($decision->questions() as $question) {
            $questions[$question->key] = $this->wire($question)['request']();
        }

        if ($questions === []) {
            throw InvalidDecision::noQuestions();
        }

        $body = ['state' => $decision->context->state];

        if ($decision->model !== null) {
            $body['model'] = $decision->model;
        }

        $body = [...$body, ...self::controls($decision), 'questions' => $questions];

        // Encoded once here so a body the HTTP client could not encode fails as the package's
        // own exception; only then are its parts encoded one by one to name the culprit.
        if (self::jsonError($body) !== null) {
            [$part, $error] = self::notUtf8($body);

            throw InvalidDecision::notUtf8($part, $error);
        }

        return $body;
    }

    /**
     * @param  ?float  $latencyMs  the round trip's latency, measured by the caller, for the result's meta
     *
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines
     * @throws InvalidDecision for a decision encode() refuses, so never for one that was sent
     */
    public function decode(Decision $decision, Response $response, ?float $latencyMs = null): Result
    {
        $body = self::object($response, 'response');
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

            $decoded[$question->key] = ['question' => $question, 'answer' => $this->wire($question)['decode']($answer, $response->body, $decision->minConfidence !== null)];
        }

        return new Result($decoded, self::decodeMeta($body, $response->body, $latencyMs));
    }

    /**
     * The checkpoint revisions a `GET /health` response reports (laya-serve's `revisions`), keyed
     * and ordered by checkpoint name. A checkpoint loaded without a known revision (from a local
     * path, say) reads as null, and a server with nothing loaded yet reports none.
     *
     * @return array<array-key, ?string> a checkpoint named like a whole number gets an int key, as in any PHP array
     *
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx, such as a host with no /health
     * @throws MalformedResponse when the body has no `revisions` object of strings or nulls
     */
    public function decodeRevisions(Response $response): array
    {
        self::failOnStatus($response);

        // Decoded to objects, not arrays: `{"0": "abc"}` must stay an object, not become a list.
        $root = json_decode($response->body);

        if (! $root instanceof stdClass) {
            throw MalformedResponse::because('The health response is not a JSON object.', $response->body);
        }

        $revisions = $root->revisions ?? null;

        if (! $revisions instanceof stdClass) {
            throw MalformedResponse::because("The health response has no 'revisions' object.", $response->body);
        }

        $decoded = [];

        foreach (get_object_vars($revisions) as $checkpoint => $revision) {
            if ($revision !== null && ! is_string($revision)) {
                throw MalformedResponse::because("The health response's revision for '{$checkpoint}' is not a string.", $response->body);
            }

            $decoded[$checkpoint] = $revision;
        }

        ksort($decoded, SORT_STRING);

        return $decoded;
    }

    /**
     * The JSON object of a successful response; an error status raises its typed exception.
     *
     * @param  string  $name  what the response is, for the error message
     * @return array<mixed>
     *
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    private static function object(Response $response, string $name): array
    {
        self::failOnStatus($response);
        // Only now: an error body is read by ErrorDetail.
        $body = json_decode($response->body, true);

        if (! is_array($body) || array_is_list($body)) {
            throw MalformedResponse::because("The {$name} is not a JSON object.", $response->body);
        }

        return $body;
    }

    /**
     * Raises the typed exception for any status but a 2xx.
     *
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    private static function failOnStatus(Response $response): void
    {
        $status = $response->status;

        if ($status >= 500) {
            throw HostUnavailable::serverError($status, ErrorDetail::of($response->body), $response->attempts);
        }

        if ($status >= 400) {
            throw RejectedByModel::withStatus($status, ErrorDetail::of($response->body));
        }

        if ($status < 200 || $status >= 300) {
            throw MalformedResponse::because("Unexpected HTTP status {$status}.", $response->body);
        }
    }

    /**
     * The request controls the decision sets; one left null is not sent, so the server's default applies.
     *
     * @return array{min_confidence?: float, lang?: string, lang_guess?: string, max_len?: int, head_max_len?: int}
     */
    private static function controls(Decision $decision): array
    {
        return array_filter([
            'min_confidence' => $decision->minConfidence,
            'lang' => $decision->language,
            'lang_guess' => $decision->languageHint,
            'max_len' => $decision->maxLength,
            'head_max_len' => $decision->headMaxLength,
        ], static fn (float|int|string|null $control): bool => $control !== null);
    }

    /**
     * JSON carries only UTF-8, so text that is not would fail in the HTTP client's own
     * json_encode(), with an exception that is not the package's. Every piece of a request is
     * a string, a list or object of strings, or null, so invalid UTF-8 is the only way it can
     * fail to encode.
     *
     * @return ?JsonException why the value cannot be encoded, or null when it can
     */
    private static function jsonError(mixed $value): ?JsonException
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e;
        }

        return null;
    }

    /**
     * The part of a body that json_encode() refused, as a message names it, and why: the state,
     * a question by its key, or else the pinned model, the only other text in it.
     *
     * @param  array{state: string|array<string, mixed>, model?: string, questions: array<string, array<string, mixed>>}  $body
     * @return array{string, ?JsonException}
     */
    private static function notUtf8(array $body): array
    {
        $error = self::jsonError($body['state']);

        if ($error !== null) {
            return ['The context text', $error];
        }

        foreach ($body['questions'] as $key => $request) {
            $error = self::jsonError($request);

            if ($error !== null) {
                return ["The text of question '{$key}'", $error];
            }
        }

        return ['The pinned model', self::jsonError($body['model'] ?? null)];
    }

    /**
     * The one dispatch on question type: what to send for a question and how to read its answer.
     *
     * encode() and decode() both go through here, so a type the codec knows has both halves and
     * any other type is refused by encode(), before DecisionClient sends anything. Both halves
     * are closures, so each caller builds only the one it uses.
     *
     * @param  QuestionHandle<Answer>  $question
     * @return array{request: Closure(): array<string, mixed>, decode: Closure(array<mixed>, string, bool): Answer}
     *
     * @throws InvalidDecision
     */
    private function wire(QuestionHandle $question): array
    {
        return match (true) {
            $question instanceof ChoiceQuestion => [
                'request' => static fn (): array => [
                    'type' => 'choice',
                    'instructions' => $question->instructions,
                    'criteria' => self::criteria($question),
                ],
                'decode' => fn (array $answer, string $body, bool $gated): ChoiceAnswer => $this->decodeChoice($question, $answer, $body),
            ],
            $question instanceof ScoreQuestion => [
                'request' => static fn (): array => [
                    'type' => 'score',
                    'instructions' => $question->instructions,
                    'criteria' => $question->criteria(),
                ],
                'decode' => fn (array $answer, string $body, bool $gated): ScoreAnswer => $this->decodeScore($question, $answer, $body),
            ],
            $question instanceof YesNoQuestion => [
                'request' => static fn (): array => ['type' => 'noul', 'instructions' => $question->instructions],
                'decode' => static fn (array $answer, string $body, bool $gated): YesNoAnswer => self::decodeYesNo($question->key, $answer, $body, $gated),
            ],
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * The answer's `type` must be the one the question was sent as.
     *
     * @param  array<mixed>  $answer
     */
    private static function checkType(string $key, array $answer, string $type, string $body): void
    {
        $got = $answer['type'] ?? null;

        if ($got !== $type) {
            throw MalformedResponse::because("The answer for '{$key}' should have type '{$type}', got ".json_encode($got, JSON_THROW_ON_ERROR).'.', $body);
        }
    }

    /**
     * @param  array<mixed>  $answer
     * @param  bool  $gated  whether the decision set a minimum confidence
     */
    private static function decodeYesNo(string $key, array $answer, string $body, bool $gated): YesNoAnswer
    {
        self::checkType($key, $answer, 'noul', $body);

        $probability = $answer['noul'] ?? null;

        if (! is_int($probability) && ! is_float($probability)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'noul' probability.", $body);
        }

        if ($probability < 0 || $probability > 1) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'noul' probability outside [0, 1]: {$probability}.", $body);
        }

        return new YesNoAnswer((float) $probability, ...self::decodeAbstention($key, $answer, $body, $gated));
    }

    /**
     * The abstention gate's fields, which every answer type carries when the decision set a
     * minimum confidence: `abstention` (passed, abstained or unevaluated), `abstention_threshold`,
     * and `low_confidence: true` on abstained answers only.
     *
     * `abstention` decides. A server that sends only `low_confidence` (none of the recorded
     * ones do) reads as abstained when it is true. With neither field, the answer reads as
     * NotRequested when the decision set no minimum confidence, and as Unevaluated when it did:
     * a gate was asked for but the server gave no verdict, and a missing `low_confidence` is not
     * a pass (Laya also leaves it off answers it could not evaluate).
     *
     * @param  array<mixed>  $answer
     * @param  bool  $gated  whether the decision set a minimum confidence
     * @return array{abstention: Abstention, abstentionThreshold: int|float|null} the answer constructor's named arguments
     */
    private static function decodeAbstention(string $key, array $answer, string $body, bool $gated): array
    {
        $state = $answer['abstention'] ?? null;
        $lowConfidence = $answer['low_confidence'] ?? false;
        $threshold = $answer['abstention_threshold'] ?? null;

        if (! is_bool($lowConfidence)) {
            throw MalformedResponse::because("The answer for '{$key}' has a non-boolean 'low_confidence'.", $body);
        }

        $abstention = match ($state) {
            null => match (true) {
                $lowConfidence => Abstention::Abstained,
                $gated => Abstention::Unevaluated,
                default => Abstention::NotRequested,
            },
            'passed' => Abstention::Passed,
            'abstained' => Abstention::Abstained,
            'unevaluated' => Abstention::Unevaluated,
            default => throw MalformedResponse::because("The answer for '{$key}' has an unknown 'abstention' state: ".json_encode($state, JSON_THROW_ON_ERROR).'.', $body),
        };

        if ($threshold !== null && ! is_int($threshold) && ! is_float($threshold)) {
            throw MalformedResponse::because("The answer for '{$key}' has a non-numeric 'abstention_threshold'.", $body);
        }

        if ($threshold !== null && ($threshold < 0 || $threshold > 1)) {
            throw MalformedResponse::because("The answer for '{$key}' has an 'abstention_threshold' outside [0, 1]: {$threshold}.", $body);
        }

        return ['abstention' => $abstention, 'abstentionThreshold' => $threshold];
    }

    /**
     * The root `model`, `routing` and `usage` fields. Each is optional, since not every host
     * reports them, but one that is present must have the protocol's shape.
     *
     * @param  array<mixed>  $root
     */
    private static function decodeMeta(array $root, string $body, ?float $latencyMs): Meta
    {
        $routing = self::optionalObject($root, 'routing', 'routing', $body);
        $usage = self::optionalObject($root, 'usage', 'usage', $body);

        return new Meta(
            model: self::optionalString($root, 'model', 'model', $body),
            checkpoint: self::optionalString($routing, 'model', 'routing.model', $body),
            routingReason: self::optionalString($routing, 'reason', 'routing.reason', $body),
            inputTokens: self::optionalTokenCount($usage, 'input_tokens', $body),
            outputTokens: self::optionalTokenCount($usage, 'output_tokens', $body),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<mixed>  $parent
     * @return array<mixed> empty when the field is absent or null
     */
    private static function optionalObject(array $parent, string $field, string $path, string $body): array
    {
        $value = $parent[$field] ?? [];

        // `{}` decodes to [], which array_is_list() also accepts.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw MalformedResponse::because("The response's '{$path}' is not an object.", $body);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $parent
     */
    private static function optionalString(array $parent, string $field, string $path, string $body): ?string
    {
        $value = $parent[$field] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw MalformedResponse::because("The response's '{$path}' is not a string.", $body);
        }

        return $value;
    }

    /**
     * A whole number written with a fraction or exponent (`12.0`, `1e2`) is the same JSON number
     * as `12` or `100`, so it is read as that int. Any other value is still refused.
     *
     * @param  array<mixed>  $usage
     * @return ?non-negative-int
     */
    private static function optionalTokenCount(array $usage, string $field, string $body): ?int
    {
        $value = $usage[$field] ?? null;

        if (is_float($value)) {
            // A fraction, or a value no int can hold, doesn't survive the round trip.
            $whole = (int) $value;
            $value = (float) $whole === $value ? $whole : $value;
        }

        if ($value !== null && (! is_int($value) || $value < 0)) {
            throw MalformedResponse::because("The response's 'usage.{$field}' is not a non-negative integer.", $body);
        }

        return $value;
    }

    /**
     * label => description (null for none), as a JSON object even when the labels are whole
     * numbers: PHP would hold `[0 => ..., 1 => ...]` as a list and send a JSON array.
     *
     * @param  ChoiceQuestion<BackedEnum>  $question
     */
    private static function criteria(ChoiceQuestion $question): stdClass
    {
        $criteria = new stdClass;

        foreach ($question->options() as ['case' => $case, 'description' => $description]) {
            $criteria->{$case->value} = $description;
        }

        return $criteria;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  ChoiceQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     * @return ChoiceAnswer<TEnum>
     */
    private function decodeChoice(ChoiceQuestion $question, array $answer, string $body): ChoiceAnswer
    {
        $key = $question->key;
        self::checkType($key, $answer, 'choice', $body);

        $choice = $answer['choice'] ?? null;

        if (! is_string($choice)) {
            throw MalformedResponse::because("The answer for '{$key}' has no string 'choice'.", $body);
        }

        $labelled = [];
        $chosen = null;

        foreach ($question->options() as ['case' => $case]) {
            $label = (string) $case->value;
            $labelled[] = ['label' => $label, 'case' => $case, 'shown' => "'{$label}'"];

            if ($label === $choice) {
                $chosen = $case;
            }
        }

        $distribution = self::distribution($key, $answer, $labelled, "options of {$question->enum}", $body);

        if ($chosen === null) {
            throw MalformedResponse::because("The answer for '{$key}' names '{$choice}', which is not an option of {$question->enum}.", $body);
        }

        // The raw confidence first, so a response wrong in both says so about it, as it always has.
        $confidence = $this->confidence($key, $answer, $body);

        return new ChoiceAnswer(
            $chosen,
            self::answerConfidence($key, $answer, $distribution->probabilityOf($chosen), $body),
            $confidence,
            $distribution,
        );
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     * @return ScoreAnswer<TEnum>
     */
    private function decodeScore(ScoreQuestion $question, array $answer, string $body): ScoreAnswer
    {
        $key = $question->key;
        self::checkType($key, $answer, 'score', $body);

        // The server knows the levels only by index, lowest first: criteria went out as a
        // list, and legend and probabilities come back keyed "0", "1", ... (int keys here).
        // Its `score` is the expected index; ScoreAnswer takes the expected level over the
        // backing values from the probabilities instead, so the score is only checked.
        $levels = $question->levels();
        $highestIndex = count($levels) - 1;
        $score = $answer['score'] ?? null;

        if (! is_int($score) && ! is_float($score)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'score'.", $body);
        }

        if ($score < 0 || $score > $highestIndex) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'score' outside the level indexes 0 to {$highestIndex}: {$score}.", $body);
        }

        self::checkLegend($question, $answer, $body);

        $labelled = [];

        foreach ($levels as $index => ['case' => $case]) {
            // The index is not the backing value unless the levels are 0..n-1: name the case too.
            $labelled[] = ['label' => $index, 'case' => $case, 'shown' => "'{$index}' (".$case::class."::{$case->name})"];
        }

        $distribution = self::distribution($key, $answer, $labelled, "levels of {$question->enum}", $body);
        $value = null;
        $highest = 0.0;

        // The server sends no most likely level for a score, only probabilities rounded to 4
        // places, so the lowest of levels equally likely after rounding wins (as np.argmax
        // picks the first). A level with probability 0 is never the most likely one, so all
        // zeros leave no value.
        foreach ($distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            if ($probability > $highest) {
                $value = $case;
                $highest = $probability;
            }
        }

        if ($value === null) {
            throw MalformedResponse::because("The answer for '{$key}' gives no level a probability above 0.", $body);
        }

        $confidence = $this->confidence($key, $answer, $body);

        return new ScoreAnswer(
            $value,
            self::answerConfidence($key, $answer, $highest, $body),
            $confidence,
            $distribution,
        );
    }

    /**
     * The legend must name every level, by index, with the description that was sent: a
     * mismatch means the server scored some other scale. A level sent without a description
     * (null, where the profile allows it) has nothing to compare, so its entry may be null,
     * any text, or missing.
     *
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     */
    private static function checkLegend(ScoreQuestion $question, array $answer, string $body): void
    {
        $legend = $answer['legend'] ?? null;

        if (! is_array($legend)) {
            throw MalformedResponse::because("The answer for '{$question->key}' has no 'legend' object.", $body);
        }

        $expected = $question->criteria();
        $sorted = $legend;

        foreach ($expected as $index => $description) {
            $entry = $sorted[$index] ?? null;

            if ($description === null && ($entry === null || is_string($entry))) {
                $sorted[$index] = null;
            }
        }

        ksort($sorted);

        if ($sorted !== $expected) {
            $json = static fn (array $legend): string => json_encode($legend, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            throw MalformedResponse::because("The answer for '{$question->key}' has a legend that does not match the levels of {$question->enum}: expected {$json($expected)}, got {$json($legend)}.", $body);
        }
    }

    /**
     * The answer's `probabilities`, one per label, as a Distribution in the order given.
     *
     * JSON object keys that are whole numbers come back from json_decode() as int keys, and an
     * object keyed "0", "1", ... as a list; PHP looks a whole-number string label up as that
     * int key, so a label may be either.
     *
     * @template TEnum of BackedEnum
     *
     * @param  array<mixed>  $answer
     * @param  non-empty-list<array{label: int|string, case: TEnum, shown: string}>  $labelled  each
     *                                                                                          label with its case and how a message names it
     * @param  string  $labels  what the labels are, for the message ("options of Department")
     * @return Distribution<TEnum>
     */
    private static function distribution(string $key, array $answer, array $labelled, string $labels, string $body): Distribution
    {
        $probabilities = $answer['probabilities'] ?? null;

        if (! is_array($probabilities)) {
            throw MalformedResponse::because("The answer for '{$key}' has no 'probabilities' object.", $body);
        }

        $entries = [];

        foreach ($labelled as ['label' => $label, 'case' => $case, 'shown' => $shown]) {
            $probability = $probabilities[$label] ?? null;

            if (! is_int($probability) && ! is_float($probability)) {
                throw MalformedResponse::because("The answer for '{$key}' has no numeric probability for {$shown}.", $body);
            }

            if ($probability < 0 || $probability > 1) {
                throw MalformedResponse::because("The answer for '{$key}' has a probability for {$shown} outside [0, 1]: {$probability}.", $body);
            }

            $entries[] = ['case' => $case, 'probability' => (float) $probability];
        }

        // array_diff() compares as strings, so int keys match their whole-number labels.
        $extra = array_diff(array_keys($probabilities), array_column($labelled, 'label'));

        if ($extra !== []) {
            $quoted = implode(', ', array_map(static fn (int|string $label): string => "'{$label}'", $extra));

            throw MalformedResponse::because("The answer for '{$key}' has probabilities for labels that are not {$labels}: {$quoted}.", $body);
        }

        return new Distribution($entries);
    }

    /**
     * The model's raw `confidence`, tagged with this codec's profile's name.
     *
     * @param  array<mixed>  $answer
     */
    private function confidence(string $key, array $answer, string $body): Confidence
    {
        $confidence = $answer['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'confidence'.", $body);
        }

        return new Confidence((float) $confidence, $this->profile->name);
    }

    /**
     * The server's `answer_confidence` (laya-serve, possibly calibrated), or, when it sends none
     * (Jev, or LAYA_JEV_STRICT), the probability of the reported answer, which is what it means.
     *
     * @param  array<mixed>  $answer
     */
    private static function answerConfidence(string $key, array $answer, float $fallback, string $body): float
    {
        $value = $answer['answer_confidence'] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'answer_confidence'.", $body);
        }

        if ($value < 0 || $value > 1) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'answer_confidence' outside [0, 1]: {$value}.", $body);
        }

        return (float) $value;
    }
}
