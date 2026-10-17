<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use Symfony\Component\Process\Process;

use function array_filter;
use function array_map;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function in_array;
use function sort;

/**
 * One of the runnable scripts in examples/, run as a reader would run it: `php examples/<name>.php`
 * from the package root, in a PHP process of its own.
 *
 * Tests/Docs runs the one that needs no server (the testing fake) in the main suite; the
 * Integration suite runs the rest against the Laya container at SYSTEM_ONE_URL.
 */
final readonly class Example
{
    /** The example that runs without a model server: it answers from SystemOne::fake(). */
    public const string WITHOUT_SERVER = 'testing-with-the-fake';

    /**
     * What each example that needs a server must print, as patterns over its whole output. They
     * hold for any answer a model could give, since the Integration suite runs them against the
     * real checkpoint.
     */
    public const array SERVER_EXPECTATIONS = [
        'yes-no' => [
            '/^churn: probability [01]\.\d{4}$/m',
            '/^isYes\(\): (yes|no)$/m',
            '/^verdict\(yes: 0\.8, no: 0\.2\): (Yes|No|Unsure)$/m',
        ],
        'choice' => [
            '/^department: (billing|technical|other) \(Department::(Billing|Technical|Other)\)$/m',
            '/^answerConfidence: [01]\.\d{4}$/m',
            '/^confidence: -?[01]\.\d{4} \(profile laya\)$/m',
            '/^distribution: billing [01]\.\d{4}, technical [01]\.\d{4}, other [01]\.\d{4}$/m',
            '/^probabilityOf\(Department::Other\): [01]\.\d{4}$/m',
            '/^isConfident\(0\.8\): (yes|no)$/m',
        ],
        'score' => [
            '/^urgency: (Low|Medium|High|Critical) \(level [0-3]\)$/m',
            '/^expected: [0-3]\.\d{2}$/m',
            '/^answerConfidence: [01]\.\d{4}$/m',
            '/^distribution: 0 [01]\.\d{4}, 1 [01]\.\d{4}, 2 [01]\.\d{4}, 3 [01]\.\d{4}$/m',
            '/^expected at least High: (yes|no)$/m',
        ],
        'thresholds-and-abstention' => [
            '/^department: (billing|technical|other), answerConfidence [01]\.\d{4}, (Passed|Abstained) at 0\.6$/m',
            '/^urgency: [0-3], answerConfidence [01]\.\d{4}, (Passed|Abstained) at 0\.6$/m',
            '/^churn: [01]\.\d{4}, answerConfidence [01]\.\d{4}, (Passed|Abstained) at 0\.6$/m',
            '/^ambiguous churn: [01]\.\d{4}, answerConfidence [01]\.\d{4}, Abstained at 0\.99 -> send to a human$/m',
            '/^without a server-side gate: NotRequested, isConfident\(0\.8\) (yes|no)$/m',
        ],
        'request-controls' => [
            '/^model: \S+$/m',
            '/^checkpoint: english$/m',
            '/^routing reason: .+$/m',
            '/^input tokens: [1-9]\d*$/m',
            '/^output tokens: \d+$/m',
            '/^latency: \d+ ms$/m',
            '/^churn: [01]\.\d{4}$/m',
        ],
        'batch' => [
            '/^3 results, [1-9]\d* input tokens$/m',
            '/^#0 department (billing|technical|other), churn [01]\.\d{4}$/m',
            '/^#1 department (billing|technical|other), churn [01]\.\d{4}$/m',
            '/^#2 department (billing|technical|other), churn [01]\.\d{4}$/m',
        ],
        'connections' => [
            '/^default connection: local$/m',
            '/^local \(remote: no\): churn [01]\.\d{4}$/m',
            '/^laya-hosted \(remote: yes\): (refused before sending: .*api_key.*|has a key, so it would send to https:\/\/\S+)$/m',
            '/^jev \(remote: yes\): (refused before sending: .*api_key.*|has a key, so it would send to https:\/\/\S+)$/m',
        ],
        'events-and-caching' => [
            '/^DecisionMade: local, questions churn, cached no, context not included$/m',
            '/^DecisionMade: local, questions churn, cached yes, context not included$/m',
            '/^same answer from the cache: yes$/m',
            '/^DecisionFailed: local, questions churn, InvalidDecision$/m',
        ],
    ];

    /** Files in examples/ that are not examples themselves. */
    private const array SUPPORT = ['bootstrap'];

    public function __construct(
        public string $name,
        public int $exitCode,
        public string $output,
        public string $errors,
    ) {}

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/examples';
    }

    /**
     * Runs the example and waits for it (at most two minutes).
     *
     * @param  array<string, string>  $environment  set on top of this process's environment
     */
    public static function run(string $name, array $environment = []): self
    {
        $process = new Process([PHP_BINARY, self::directory()."/{$name}.php"], dirname(__DIR__, 2), ['XDEBUG_MODE' => 'off', ...$environment], timeout: 120);
        $process->run();

        return new self($name, $process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * The name of every example script in examples/, sorted.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $paths = glob(self::directory().'/*.php');
        $names = array_map(static fn (string $path): string => basename($path, '.php'), $paths === false ? [] : $paths);
        $names = array_values(array_filter($names, static fn (string $name): bool => ! in_array($name, self::SUPPORT, true)));
        sort($names);

        return $names;
    }

    /** What the run printed to both streams, for a failure message. */
    public function transcript(): string
    {
        return "examples/{$this->name}.php exited {$this->exitCode}.\n--- stdout\n{$this->output}\n--- stderr\n{$this->errors}";
    }
}
