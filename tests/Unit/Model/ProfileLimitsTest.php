<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Model\ModelProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;

/*
 * The codec checks every decision against its connection's profile before encoding it, so a
 * decision the model would refuse never leaves PHP. Each limit is tested just below, at and
 * just above its boundary.
 */

/** A decision asking one choice question with the given number of options. */
function choiceWithOptions(int $count): Decision
{
    return Decision::about(Context::text('We were billed twice.'))
        ->ask(Question::choice('label', OptionsEnum::withCases($count), 'Which label fits?'));
}

dataset('choice option limits', [
    'laya' => [fn (): ModelProfile => new LayaProfile, 100],
    'laya with tournament grouping' => [fn (): ModelProfile => new LayaProfile(tournamentGrouping: true), 255],
    'jev' => [fn (): ModelProfile => new JevProfile, 255],
]);

it('sends a choice question with one option fewer than the limit', function (ModelProfile $profile, int $limit): void {
    $encoded = (new SystemOneCodec($profile))->encode(choiceWithOptions($limit - 1));

    expect((array) $encoded['questions']['label']['criteria'])->toHaveCount($limit - 1);
})->with('choice option limits');

it('sends a choice question with exactly as many options as the limit', function (ModelProfile $profile, int $limit): void {
    $encoded = (new SystemOneCodec($profile))->encode(choiceWithOptions($limit));

    expect((array) $encoded['questions']['label']['criteria'])->toHaveCount($limit);
})->with('choice option limits');

it('refuses a choice question with one option more than the limit, naming the key and the limit', function (ModelProfile $profile, int $limit): void {
    $over = $limit + 1;

    expect(fn () => (new SystemOneCodec($profile))->encode(choiceWithOptions($over)))
        ->toThrow(InvalidDecision::class, "Choice question 'label' has {$over} options, more than the {$limit} the {$profile->name} profile allows per choice question.");
})->with('choice option limits');
