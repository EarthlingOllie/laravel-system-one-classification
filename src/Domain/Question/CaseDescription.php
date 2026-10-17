<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use Error;
use ReflectionClassConstant;

/**
 * Reads an enum case's description the way every enum-backed question does: from
 * DescribesCases when the enum implements it, otherwise from a #[Describe] on the case.
 */
final readonly class CaseDescription
{
    /**
     * @param  Closure(Error): InvalidDecision  $malformed  builds the error for a #[Describe] PHP cannot build
     * @return ?string null when the case has no description
     *
     * @throws InvalidDecision from $malformed
     */
    public static function of(BackedEnum $case, Closure $malformed): ?string
    {
        if ($case instanceof DescribesCases) {
            return $case->describe();
        }

        $attributes = new ReflectionClassConstant($case, $case->name)->getAttributes(Describe::class);

        // PHP checks an attribute's arguments, and that it is not repeated, only when it is
        // built here. Every such failure is an Error (ArgumentCountError and TypeError among
        // them), and Describe's constructor does nothing else that could throw.
        try {
            return ($attributes[0] ?? null)?->newInstance()->description;
        } catch (Error $e) {
            throw $malformed($e);
        }
    }
}
