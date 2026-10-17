<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;

/** @return Distribution<Severity> */
function severityDistribution(): Distribution
{
    return new Distribution([
        ['case' => Severity::Minor, 'probability' => 0.1],
        ['case' => Severity::Major, 'probability' => 0.6],
        ['case' => Severity::Critical, 'probability' => 0.3],
    ]);
}

/** @return ScoreAnswer<Severity> */
function majorAnswer(float $expected = 5.6, float $answerConfidence = 0.6): ScoreAnswer
{
    return new ScoreAnswer(Severity::Major, $expected, $answerConfidence, new Confidence(0.2, 'laya'), severityDistribution());
}

it('holds the most likely level, the expected level, both confidences and the distribution', function (): void {
    $answer = majorAnswer();

    expect($answer->value)->toBe(Severity::Major)
        ->and($answer->expected)->toBe(5.6)
        ->and($answer->answerConfidence)->toBe(0.6)
        ->and($answer->confidence)->toEqual(new Confidence(0.2, 'laya'))
        ->and($answer->distribution->cases())->toBe([Severity::Minor, Severity::Major, Severity::Critical]);
});

it('gives the probability of any level', function (): void {
    expect(majorAnswer()->probabilityOf(Severity::Critical))->toBe(0.3)
        ->and(majorAnswer()->probabilityOf(Severity::Minor))->toBe(0.1);
});

it('is confident when answerConfidence reaches the threshold', function (float $threshold, bool $confident): void {
    expect(majorAnswer(answerConfidence: 0.6)->isConfident($threshold))->toBe($confident);
})->with([
    [0.0, true],
    [0.6, true],
    [0.6001, false],
    [1.0, false],
]);

it('compares against answerConfidence, not the distribution', function (): void {
    // P(Major) is 0.6; only answerConfidence counts.
    expect(majorAnswer(answerConfidence: 0.9)->isConfident(0.8))->toBeTrue()
        ->and(majorAnswer(answerConfidence: 0.5)->isConfident(0.55))->toBeFalse();
});

it('rejects a threshold outside [0, 1]', function (float $threshold): void {
    majorAnswer()->isConfident($threshold);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The threshold must be between 0 and 1');

it('rejects an answerConfidence outside [0, 1]', function (float $answerConfidence): void {
    majorAnswer(answerConfidence: $answerConfidence);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The answer confidence must be between 0 and 1');

it('accepts an expected level anywhere from the lowest level to the highest, bounds included', function (float $expected): void {
    expect(majorAnswer(expected: $expected)->expected)->toBe($expected);
})->with([1.0, 1.0001, 7.25, 9.9999, 10.0]);

it('rejects an expected level outside the levels', function (float $expected): void {
    majorAnswer(expected: $expected);
})->with([0.9999, 10.0001, -1.0, NAN, INF])->throws(InvalidDistribution::class);

it('names the levels an out-of-range expected level misses', function (): void {
    majorAnswer(expected: 11.5);
})->throws(InvalidDistribution::class, 'The expected level 11.5 is outside the levels of '.Severity::class.', which run from 1 to 10.');

it('rejects a value the distribution is not over', function (): void {
    new ScoreAnswer(Department::Billing, 5.0, 0.5, new Confidence(0.1, 'laya'), severityDistribution());
})->throws(InvalidDistribution::class, Department::class.'::Billing is not a case of this distribution');

it('cannot be changed after construction', function (): void {
    $answer = majorAnswer();

    expect(fn () => $answer->expected = 1.0)->toThrow(Error::class, 'Cannot modify readonly property');
});
