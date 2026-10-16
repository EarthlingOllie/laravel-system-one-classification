<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use BackedEnum;
use Countable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use Generator;
use IteratorAggregate;

use function array_column;
use function count;
use function in_array;

/**
 * The probability the model gave each case of an enum, in a fixed order.
 *
 * The order is the one it was built in: the option order of the question (declaration order
 * for a choice). `foreach ($distribution as $case => $probability)` walks it. The server rounds
 * each probability, so they need not add up to exactly 1.
 *
 * @template TCase of BackedEnum
 *
 * @implements IteratorAggregate<TCase, float>
 */
final readonly class Distribution implements Countable, IteratorAggregate
{
    /** @var class-string<TCase> */
    private string $enum;

    /**
     * @param  list<array{case: TCase, probability: float}>  $entries  every case of one enum, once
     *
     * @throws InvalidDistribution when the entries are empty or do not cover every case of one enum exactly once
     * @throws InvalidProbability when a probability is outside [0, 1]
     */
    public function __construct(private array $entries)
    {
        $enum = ($entries[0] ?? throw InvalidDistribution::empty())['case']::class;
        $seen = [];

        foreach ($entries as ['case' => $case, 'probability' => $probability]) {
            Probability::check("The probability of '{$case->value}'", $probability);

            if ($case::class !== $enum || in_array($case, $seen, true)) {
                throw InvalidDistribution::notOverEnum($enum);
            }

            $seen[] = $case;
        }

        if (count($seen) !== count($enum::cases())) {
            throw InvalidDistribution::notOverEnum($enum);
        }

        $this->enum = $enum;
    }

    /**
     * @param  TCase  $case
     *
     * @throws InvalidDistribution when the case belongs to another enum
     */
    public function probabilityOf(BackedEnum $case): float
    {
        foreach ($this->entries as $entry) {
            if ($entry['case'] === $case) {
                return $entry['probability'];
            }
        }

        throw InvalidDistribution::unknownCase($case, $this->enum);
    }

    /**
     * @return list<TCase> in the distribution's order
     */
    public function cases(): array
    {
        return array_column($this->entries, 'case');
    }

    /**
     * @return Generator<TCase, float>
     */
    public function getIterator(): Generator
    {
        foreach ($this->entries as $entry) {
            yield $entry['case'] => $entry['probability'];
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
