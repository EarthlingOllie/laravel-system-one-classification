<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use Error;
use ReflectionEnum;

use function array_map;
use function enum_exists;
use function trim;
use function usort;

/**
 * Rate on an ordinal scale, where the levels are the cases of an int-backed enum (Laya's `score`).
 *
 * The backing values order the levels, lowest first, whatever order the cases are declared
 * in. Every level needs a description (Laya rejects one without), from DescribesCases when the
 * enum implements it, otherwise from a #[Describe] attribute on the case. Build it with
 * Question::score().
 *
 * @template TEnum of BackedEnum
 *
 * @implements QuestionHandle<ScoreAnswer<TEnum>>
 */
final readonly class ScoreQuestion implements QuestionHandle
{
    /** @var non-empty-string */
    public string $key;

    /** @var non-empty-list<array{case: TEnum, description: string}> */
    private array $levels;

    /**
     * @param  class-string<TEnum>  $enum  an int-backed enum
     *
     * @throws InvalidDecision when the key or instructions are invalid, the class is not an
     *                         int-backed enum, the enum has no cases, or a case has no
     *                         description, a blank one, or a #[Describe] PHP cannot build
     */
    public function __construct(string $key, public string $enum, public string $instructions)
    {
        $this->key = QuestionKey::validate($key);
        Instructions::validate($key, $instructions);

        // enum_exists() first: an interface that extends BackedEnum has no backing type to read.
        if (! enum_exists($enum) || (string) new ReflectionEnum($enum)->getBackingType() !== 'int') {
            throw InvalidDecision::notIntBackedEnum($key, $enum);
        }

        $cases = $enum::cases();

        if ($cases === []) {
            throw InvalidDecision::noLevels($key, $enum);
        }

        usort($cases, static fn (BackedEnum $a, BackedEnum $b): int => $a->value <=> $b->value);

        $this->levels = array_map(
            static fn (BackedEnum $case): array => ['case' => $case, 'description' => self::describe($key, $case)],
            $cases,
        );
    }

    /**
     * Every level with its description, lowest backing value first.
     *
     * @return non-empty-list<array{case: TEnum, description: string}>
     */
    public function levels(): array
    {
        return $this->levels;
    }

    public function answerType(): string
    {
        return ScoreAnswer::class;
    }

    private static function describe(string $key, BackedEnum $case): string
    {
        $description = CaseDescription::of($case, static fn (Error $e): InvalidDecision => InvalidDecision::malformedLevelDescribe($key, $case, $e));

        if ($description === null) {
            throw InvalidDecision::undescribedLevel($key, $case);
        }

        if (trim($description) === '') {
            throw InvalidDecision::blankLevelDescription($key, $case);
        }

        return $description;
    }
}
