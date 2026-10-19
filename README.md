# laravel-system-one-classification

A typed, immutable Laravel client for System 1 decision models: [Laya](https://github.com/NandhaKishorM/laya), the hosted Laya API and TypeSafe's Jev.

You ask typed questions about a piece of text (yes/no, pick one case of an enum, or rate on an ordinal scale) and get typed answers back: an enum case, a probability, and the model's confidence in it. The package also ships a Docker setup that runs Laya on your own machine, so nothing has to leave it.

```php
$department = Question::choice('department', Department::class, 'Which department should handle this?');

$result = SystemOne::decide(Decision::about(Context::text($ticket))->ask($department));

$result->get($department)->value;            // Department::Billing
$result->get($department)->answerConfidence; // 0.93
```

> **Status:** unreleased. The package name, `earthlingollie/laravel-system-one-classification`, and the vendor are provisional, and there are no tagged releases yet.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Defining enums](#defining-enums)
- [Asking questions](#asking-questions)
- [Reading answers](#reading-answers)
- [Choosing thresholds](#choosing-thresholds)
- [Abstention](#abstention)
- [Request controls and model limits](#request-controls-and-model-limits)
- [Batch calls](#batch-calls)
- [Connections](#connections)
- [Errors](#errors)
- [Testing with the fake](#testing-with-the-fake)
- [Events](#events)
- [Caching](#caching)
- [Artisan commands](#artisan-commands)
- [The local server](#the-local-server)
- [Troubleshooting](#troubleshooting)
- [Runnable examples](#runnable-examples)
- [Working on the package](#working-on-the-package)

## Requirements

- **PHP 8.4** or later.
- **Laravel 13** (`illuminate/http` and `illuminate/support` ^13).
- **Docker** with Compose v2, for the local Laya server. You don't need it if you only use the hosted connections, or only the testing fake.
- **Resources for the local server:**
  - **Memory:** give Docker at least 8 GB. The English checkpoint uses about 2 GB once loaded and peaks near 2.8 GB while it loads. Each extra checkpoint (such as `multilingual`) needs more.
  - **Disk:** about 10 GB free. The image is about 1.7 GB, the English weights add about 0.85 GB, and the build needs room on top.
  - **CPU:** enough on its own. A GPU is optional (NVIDIA only; see [The local server](#the-local-server)). On a 4-vCPU cloud VM, one yes/no question took about 0.3 s and three questions in one call about 0.6 s.

## Installation

```bash
composer require earthlingollie/laravel-system-one-classification
```

Until the package is on Packagist, require it from a clone through a [path repository](https://getcomposer.org/doc/05-repositories.md#path) in your app's `composer.json`, then require it as `@dev`:

```json
"repositories": [
    { "type": "path", "url": "../laya-php" }
]
```

```bash
composer require earthlingollie/laravel-system-one-classification:@dev
```

Laravel discovers the service provider and the `SystemOne` facade alias on its own.

### Set up the local server: `system-one:install`

```bash
php artisan system-one:install
```

It asks where the server should run. `--mode=standalone` or `--mode=sail` skips the question, and `--no-interaction` picks standalone. The command only ever adds things: an existing file, service, volume or `.env` key is left as it is, so running it again changes nothing.

**Standalone** (the default) is for an app that runs on your machine, for example with `php artisan serve`:

1. It publishes `config/system-one.php`.
2. It writes `compose.system-one.yaml` into the app. Its build context points at the installed package's `docker/laya`.
3. It sets `SYSTEM_ONE_URL=http://localhost:8000` in `.env`.

Then start the server and check it:

```bash
docker compose -f compose.system-one.yaml up -d --wait
php artisan system-one:health
```

The first start builds the image and downloads the checkpoint, which can take a while (see [Troubleshooting](#troubleshooting)). `--wait` returns once the checkpoint is loaded. The Compose project is named `system-one`, so every app on your machine that uses this file shares one server on port 8000, and one copy of the weights.

**Sail** is for an app that runs in [Laravel Sail](https://laravel.com/docs/sail). A new Laravel 13 app doesn't include Sail, so set it up first with `composer require laravel/sail --dev` and `php artisan sail:install`.

```bash
php artisan system-one:install --mode=sail
```

1. It publishes `config/system-one.php`.
2. It adds a `laya` service (on the `sail` network, when the file has one) and a `sail-laya` volume for the weights to your Sail Compose file (`compose.yaml`, or whichever of `compose.yml`, `docker-compose.yaml` and `docker-compose.yml` you have). It only inserts new lines, under `services:` and `volumes:`, and leaves every existing line as it is.
3. It sets `SYSTEM_ONE_URL=http://laya:8000` in `.env`, so the `laravel.test` container reaches it by its service name.

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan system-one:health
```

If `SYSTEM_ONE_URL` is already in `.env`, `system-one:install` leaves it alone and prints the value it expected.

### Configuration

`system-one:install` publishes `config/system-one.php`; `php artisan vendor:publish --tag=system-one-config` does the same on its own. The local connection reads these `.env` keys:

| Key | Default | Meaning |
|---|---|---|
| `SYSTEM_ONE_CONNECTION` | `local` | The default connection. |
| `SYSTEM_ONE_URL` | `http://localhost:8000` | The local server. Requests go to `{url}/v1/systemone`. |
| `SYSTEM_ONE_API_KEY` | none | Only needed when the server runs with `LAYA_API_KEY`. |
| `SYSTEM_ONE_TIMEOUT` | `30` | Seconds per attempt. |
| `SYSTEM_ONE_RETRIES` | `2` | Retries after a 5xx, a timeout or a failed connection, at most 10. |
| `SYSTEM_ONE_RETRY_DELAY` | `200` | Milliseconds before the first retry, doubled before each further one. |
| `SYSTEM_ONE_PROFILE` | `laya` | The model behind the URL: `laya` or `jev`. |
| `SYSTEM_ONE_MODEL` | none | The checkpoint to ask when a decision pins none, such as `english`. Empty lets the server choose. |
| `SYSTEM_ONE_MODELS` | none | Extra model names the server accepts (its `LAYA_EXTRA_MODELS`), comma-separated. |
| `SYSTEM_ONE_TOURNAMENT_GROUPING` | `false` | `true` when the server runs with `LAYA_TOURNAMENT_GROUP`, which allows 255 choice options instead of 100. |

The hosted connections, events and the cache have keys of their own, described in their sections.

## Defining enums

Choice and score questions take their options from a backed enum. The backing value is the label the model sees, and `#[Describe]` gives it a description:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';

    #[Describe('everything else')]
    case Other = 'other';
}
```

A score question needs an **int-backed** enum. Its levels are ordered by backing value, lowest first, whatever order you declare them in:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

enum Urgency: int
{
    #[Describe('can wait')]
    case Low = 0;

    #[Describe('soon')]
    case Medium = 1;

    #[Describe('today')]
    case High = 2;

    #[Describe('blocking')]
    case Critical = 3;
}
```

Rules:

- A choice case without a description goes out as its bare label. Descriptions help the model, so give one to every case.
- On Laya every score level must have a description. A score question with an undescribed level is refused before anything is sent (`InvalidDecision`).
- A blank label or description, an enum without cases, a non-backed enum, or a `#[Describe]` PHP cannot build is an `InvalidDecision` when you build the question.

For descriptions that come from code, such as translations, implement `DescribesCases`. Then `describe()` is used, and any `#[Describe]` is ignored:

```php
use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;

enum Department: string implements DescribesCases
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Other = 'other';

    public function describe(): string
    {
        return __("departments.{$this->value}");
    }
}
```

The description is read when you build the question, and it is part of the question: the model reads it. So a question rebuilt under another locale is a different question. Read the result with the same question object you asked with.

## Asking questions

There are three question types. Each takes a key that is unique within the decision, and the instructions the model reads:

```php
use App\Enums\Department;
use App\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;

$department = Question::choice('department', Department::class, 'Which department should handle this?');
$urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
$churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

$decision = Decision::about(Context::text($ticket->body))
    ->ask($department, $urgency, $churn);

$result = SystemOne::decide($decision);
```

- **`Question::yesNo()`** asks whether a statement about the text is true. The answer is a probability.
- **`Question::choice()`** asks the model to pick one case of a backed enum.
- **`Question::score()`** asks for a level on an ordinal scale: an int-backed enum.

The context is plain text (`Context::text()`), or named fields that go out as a JSON object (`Context::fields()`):

```php
$context = Context::fields(['subject' => $ticket->subject, 'body' => $ticket->body]);
```

Field values must be plain data: strings, numbers, booleans, null, and arrays of those. Blank text, empty fields and objects are refused with an `InvalidDecision`.

Everything is immutable. `ask()` and every `with...()` method return a new `Decision`, so you can build one base decision and vary it. A key must not be blank, repeated, or a whole number such as `'0'`. Instead of the facade you can inject `EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver`, which has the same `decide()`, `decideMany()` and `connection()` and is swapped by the [testing fake](#testing-with-the-fake) too.

## Reading answers

`$result->get($question)` returns the answer to that question, typed for its question type. With PHPStan or Psalm, `$result->get($department)->value` is known to be a `Department`, with no casting:

```php
$answer = $result->get($department);

$answer->value;                          // Department::Billing
$answer->answerConfidence;               // 0.93: the probability of that answer
$answer->confidence->value;              // 0.45: the model's raw confidence
$answer->confidence->profile;            // 'laya': the model profile that produced it
$answer->isConfident(0.8);               // answerConfidence >= 0.8
$answer->probabilityOf(Department::Other);
$answer->distribution->entries();        // [['case' => Department::Billing, 'probability' => 0.93], ...]
```

**Yes/no** (`YesNoAnswer`):

- `probability`: the probability that the statement is true.
- `isYes(0.8)`: whether `probability` reaches the threshold (0.5 when you give none).
- `verdict(yes: 0.8, no: 0.2)`: `Verdict::Yes` at or above `yes`, `Verdict::No` at or below `no`, and `Verdict::Unsure` in between.

**Choice** (`ChoiceAnswer`):

- `value`: the enum case the model picked.
- `answerConfidence`: the probability of that case.
- `confidence`: the model's raw confidence, a `Confidence` with `value` and `profile`.
- `distribution`: the probability of every case, in the enum's declaration order. `entries()` lists cases with their probabilities, `cases()` the cases alone, `probabilityOf($case)` one of them, and `count()` how many there are. The server rounds each probability, so they need not add up to exactly 1.
- `probabilityOf($case)` and `isConfident($threshold)`.

**Score** (`ScoreAnswer`):

- `value`: the most likely level, as the enum case (the lowest of equally likely ones).
- `expected`: the expected level, the mean backing value weighted by the distribution. It can fall between two levels, and compares with backing values directly:

  ```php
  if ($result->get($urgency)->expected >= Urgency::High->value) {
      // escalate
  }
  ```

- `answerConfidence`, `confidence`, `distribution` (in level order), `probabilityOf()` and `isConfident()`, as on a choice answer.

**Every answer** also has `abstained`, `abstention` and `abstentionThreshold` (see [Abstention](#abstention)).

**About the call.** `$result->meta` is a `Meta` with what the server reported:

| Property | Example | |
|---|---|---|
| `model` | `laya-rl-agent` | The server's model name. |
| `checkpoint` | `english` | The checkpoint the decision was routed to. |
| `routingReason` | `English Latin text` | Why it was routed there. |
| `inputTokens`, `outputTokens` | `57`, `0` | The tokens the call used. |
| `latencyMs` | `412.5` | The round trip, measured by the client. |

Each one is null when the host did not report it. Jev sends no routing, for example, and an answer from the cache has no latency.

Reading a question the decision did not ask throws `UnknownQuestion`. Questions compare by value: an equal question built elsewhere, with the same class, key, instructions, enum and descriptions, reads the same answer.

## Choosing thresholds

Every choice and score answer has two confidence values. **Gate on `answerConfidence`**, not on the raw `confidence`.

- **`answerConfidence`** is the probability the model gave to the answer it reports. It means the same thing on every model, so a threshold carries over when you switch from local Laya to Jev, or from one checkpoint to another. laya-serve's own abstention gate compares this value too.
- **`confidence`** is the model's own measure, and each model computes it differently. Laya reports 1 minus the normalised entropy of the whole distribution, so it drops whenever the probability is spread across the other options, even when the top answer is clear. In the example response laya-ai.com documents, `billing` has an `answerConfidence` of 0.93 but a `confidence` of 0.45. Jev uses a formula of its own. That is why `confidence` is tagged with the `profile` that produced it: a threshold tuned on one profile means nothing on another. Use it for monitoring, or compare it only within one profile.

Yes/no answers have only `probability`, the probability of "yes". The confidence of a yes/no answer, which the server's gate uses, is the probability of the side it reports: `max(p, 1 - p)`. Rather than one cut-off at 0.5, use `verdict()` to leave a band in the middle for a human:

```php
match ($result->get($churn)->verdict(yes: 0.8, no: 0.2)) {
    Verdict::Yes => $this->escalate($ticket),
    Verdict::No => $this->queue($ticket),
    Verdict::Unsure => $this->askAHuman($ticket),
};
```

How to pick the numbers:

- **Start strict**, at 0.8 or so, and route everything below it to a human.
- **Measure.** Log the answers with their `answerConfidence` (the [events](#events) carry them), compare them with what people decided, and move each question's threshold to the error rate you can accept. Thresholds are per question: a two-way choice and a ten-way choice are not equally hard.
- **Mind the number of options.** For a choice over n options an even split is 1/n, so a threshold close to 1/n accepts guesses.
- **For scores**, a threshold on `expected` ("at least High") is often more useful than one on the single most likely level.

## Abstention

`withMinConfidence()` asks the server to gate every answer of the decision on its `answerConfidence`:

```php
$result = SystemOne::decide($decision->withMinConfidence(0.6));

$answer = $result->get($department);

if ($answer->abstained) {
    // Below 0.6: route it to a human. $answer->value still holds the model's best guess.
}
```

- `abstained` is true only when the server's gate said `Abstention::Abstained`.
- `abstention` holds the gate's full verdict, a `Result\Abstention`:
  - `NotRequested`: the decision set no minimum confidence, so no gate ran.
  - `Passed`: the answer reached the minimum.
  - `Abstained`: the answer fell below the minimum.
  - `Unevaluated`: a gate was asked for, but the server gave no verdict for this answer.
- `abstentionThreshold` is the threshold the answer was gated at, or null.

An abstained answer keeps its value, so you can show a reviewer what the model thought. To threshold in PHP instead, without asking the server, use `isConfident()` and `verdict()`. The minimum is one number from 0 to 1 for the whole decision.

## Request controls and model limits

```php
$decision = Decision::about(Context::text($ticket->body))
    ->ask($churn)
    ->withLanguage('de')        // the context's language (`lang`), which picks the checkpoint
    ->withLanguageHint('de')    // a weaker hint the server's language detection may overrule (`lang_guess`)
    ->withMaxLength(256)        // the token budget for the whole input (`max_len`)
    ->withHeadMaxLength(32)     // the token budget for each question (`head_max_len`)
    ->withModel('multilingual'); // pin a checkpoint (`model`)
```

A control you don't set is not sent, so the server's default applies. A model pinned with `withModel()` wins over the connection's `SYSTEM_ONE_MODEL`.

Every decision is checked against the connection's **model profile** before it is sent, so a request that is too large fails in PHP with an `InvalidDecision` that names the question and the limit:

| | Laya (`laya`) | Jev (`jev`) |
|---|---|---|
| Choice options per question | 100, or 255 with `tournament_grouping` | 255 |
| Score level descriptions | required for every level | optional |
| Model names for `withModel()` | laya-serve's checkpoints and aliases (`english`, `multilingual`, `typed-decisions`, `en`, `multi`, ...), any case, plus the connection's `models` | only the connection's `models` |
| Meaning of the raw `confidence` | 1 − normalised entropy | Jev's own formula |

laya-serve has more limits, which the server itself enforces with a 413 (a `RejectedByModel`): 64 questions per decision, 32 score levels, 512 options across all questions, and 50,000 characters of context.

## Batch calls

`decideMany()` asks one decision's questions about many contexts, through the batch endpoint:

```php
$decision = Decision::about(Context::text($tickets[0]))->ask($department, $churn)->withMinConfidence(0.6);

$results = SystemOne::decideMany(array_map(Context::text(...), $tickets), $decision);

count($results);                       // one Result per context, in the same order
$results[0]->get($department)->value;
$results->usage?->inputTokens;         // tokens for the whole batch

foreach ($results as $i => $result) {
    $result->get($churn)->probability;
}
```

- The decision supplies the questions and the request controls. Its own context is not sent, so any context will do there.
- Contexts are split into as many requests as the server's limits need: 64 states and 2 MiB per request. Every request is checked before the first is sent, so one bad context sends nothing.
- `ResultSet` is read-only. It is countable and iterable, `$results[$i]` reads a position, and `all()` returns a list. `usage` is the batch's total, or null when a request reported none.
- A batch succeeds or fails as a whole. If one request fails, its exception is thrown and no later request is sent. With the [cache](#caching) on, the answers already received are kept, so asking again sends only the rest.

## Connections

Connections work like database connections. The shipped config has three:

| Name | Host | Profile | Key |
|---|---|---|---|
| `local` (the default) | laya-serve on your machine (`SYSTEM_ONE_URL`) | `laya` | optional |
| `laya-hosted` | the hosted Laya API, `https://api.laya-ai.com` | `laya` | required |
| `jev` | TypeSafe's Jev, `https://api.typesafe.ai` | `jev` | required |

`SystemOne::decide()` uses the default connection. Name another for one decision:

```php
$result = SystemOne::connection('jev')->decide($decision);
```

> **Remote connections send your data to third parties.** `laya-hosted` is run by laya-ai.com and `jev` by TypeSafe; both bill per call. Whatever you put in the context, every question, and the instructions go to that company. Check your data-protection obligations before you send personal data to either. The local connection never leaves your machine.

That is why the remote connections are never used by accident:

- The default is `local`, and setting a remote key does not change it. Only `SYSTEM_ONE_CONNECTION` does.
- A connection marked `remote` refuses to send anything without an `api_key`. `connection()` throws an `InvalidConfiguration` before any request is made.
- Its URL must be https. Plain http is allowed only to localhost.

Configure them in `.env`:

```dotenv
SYSTEM_ONE_LAYA_HOSTED_API_KEY=...
SYSTEM_ONE_JEV_API_KEY=...
# Jev's model names are not built in: list the ones you use.
SYSTEM_ONE_JEV_MODELS=...
```

Each remote connection also reads `_URL`, `_TIMEOUT`, `_MODEL`, `_MODELS`, `_RETRIES` and `_RETRY_DELAY` under its prefix, such as `SYSTEM_ONE_JEV_TIMEOUT` or `SYSTEM_ONE_LAYA_HOSTED_RETRIES`; `laya-hosted` also has `SYSTEM_ONE_LAYA_HOSTED_TOURNAMENT_GROUPING`. To add a connection of your own, copy a block in `config/system-one.php` and give it a name. Set `'remote' => true` on any host off this machine to get the key and https checks. It cannot be inferred from the URL, because a Sail app reaches its own server over `http://laya:8000`.

**Retries.** A 5xx, a timeout or a failed connection is sent again up to `retries` times. The wait starts at `retry_delay` milliseconds and doubles before each further retry, up to 30 s. A `Retry-After` from the server wins when it is longer. A 4xx is never retried. A timed-out request may still have been answered, and billed, by the host. A host that never answers holds a decision for (retries + 1) × timeout seconds, plus the waits, so keep both small where that matters.

**The API key** goes out only as the bearer token. It never appears in an exception, an event, a log line from the package, or `system-one:health` output. The package's requests don't dispatch Laravel's HTTP client events, which would carry the header, so tools that listen for them, such as Telescope and Pulse, don't see these requests. `Http::fake()` still applies to them.

## Errors

Every exception the package throws implements `EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException`:

| Exception | When |
|---|---|
| `InvalidDecision` | The decision can't be sent: blank text or key, a repeated key, no questions, a profile limit, a model the profile doesn't know. Always thrown before anything is sent. |
| `InvalidConfiguration` | `config/system-one.php` doesn't describe a usable connection, or a remote connection has no key. |
| `HostUnavailable` | The server couldn't be reached, timed out or answered with a 5xx, after the retries. `status` holds the last HTTP status, if any. |
| `RejectedByModel` | The server answered with a 4xx. `status` and `detail` hold the status and the server's reason. |
| `MalformedResponse` | The response didn't match the protocol. `body` holds the raw response. |
| `UnknownQuestion` | `Result::get()` was given a question the decision didn't ask. |
| `InvalidProbability` | A threshold outside [0, 1], or a `verdict()` whose `no` is above its `yes`. |
| `InvalidResultSetAccess` | A `ResultSet` was read at a position it doesn't have, or written to. |
| `InvalidFakeAnswer` | A `FakeAnswer` no model could give, such as a choice confidence below 1/n, or an abstained answer on a decision with no minimum confidence. |
| `UnscriptedQuestion` | A strict fake was asked a question it has no script for. |
| `InvalidDistribution` | A `FakeAnswer` (or an answer you build yourself) names a case of another enum, or a distribution that doesn't cover its enum. |

## Testing with the fake

`SystemOne::fake()` swaps in a fake that answers from scripted answers instead of a model, and records every decision so you can assert on them. Nothing is sent anywhere, so tests need no server.

Say the app triages tickets in a class that injects `ConnectionResolver`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Department;
use App\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;

final readonly class TicketTriage
{
    public function __construct(private ConnectionResolver $systemOne) {}

    /** 'escalate', the department to send the ticket to, or 'review' for a person to decide. */
    public function triage(string $ticket): string
    {
        $department = Question::choice('department', Department::class, 'Which department should handle this?');
        $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
        $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

        $result = $this->systemOne->decide(Decision::about(Context::text($ticket))->ask($department, $urgency, $churn));

        if ($result->get($churn)->isYes(0.8) || $result->get($urgency)->expected >= Urgency::High->value) {
            return 'escalate';
        }

        $answer = $result->get($department);

        return $answer->isConfident(0.8) ? $answer->value->value : 'review';
    }
}
```

A feature test then scripts the answers and asserts on what was decided:

```php
<?php

namespace Tests\Feature;

use App\Enums\Department;
use App\Enums\Urgency;
use App\Support\TicketTriage;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use Tests\TestCase;

final class TicketTriageTest extends TestCase
{
    public function test_it_escalates_a_billing_ticket_that_threatens_to_cancel(): void
    {
        $department = Question::choice('department', Department::class, 'Which department should handle this?');
        $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
        $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

        $fake = SystemOne::fake([
            FakeAnswer::choice($department, Department::Billing, confidence: 0.92),
            FakeAnswer::score($urgency, Urgency::Medium, expected: 1.2),
            FakeAnswer::yesNo($churn, 0.91),
        ]);

        $this->assertSame('escalate', app(TicketTriage::class)->triage('Refund me or we cancel.'));

        $fake->assertDecidedTimes(1);
        $fake->assertAsked($department);
        $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'local');
    }

    public function test_it_sends_a_calm_billing_ticket_to_billing(): void
    {
        $fake = SystemOne::fake([
            FakeAnswer::choice(Question::choice('department', Department::class, 'Which department should handle this?'), Department::Billing, confidence: 0.92),
        ]);

        // Unscripted questions get a certain default: here, the lowest urgency and "no".
        $this->assertSame('billing', app(TicketTriage::class)->triage('Where can I download my invoice?'));
    }
}
```

With Pest, the same lines go in an `it('escalates ...', function () { ... })`.

**Scripted answers.** Each helper builds the same answer type the real client returns:

- `FakeAnswer::yesNo($question, 0.91)`: the probability of yes.
- `FakeAnswer::choice($question, Department::Billing, confidence: 0.92)`: the picked case and its probability (1 if you give none). The rest is shared evenly among the other options, so the confidence can't be below 1/n.
- `FakeAnswer::score($question, Urgency::High, expected: 1.7)`: the most likely level and the expected level (the level's own value if you give none). An expected level the case can't be the most likely level at is refused.
- `FakeAnswer::abstained(FakeAnswer::choice(...))`: the same answer, abstained at the decision's `withMinConfidence()`. On a decision with no minimum confidence it is refused, because no server would abstain there.

On a decision with a minimum confidence, every other scripted answer is gated as laya-serve gates it: `Abstained` below the threshold and `Passed` otherwise. Each answer's raw `confidence` is computed with Laya's formula and tagged with the connection's profile.

**Matching.** A script answers the question it was built for. Questions compare by value, so a question built in the test matches the one your app builds, as long as the key, instructions, enum and descriptions are the same. When several scripts answer one question, the last one wins.

**Unscripted questions** get a certain default answer: no for a yes/no question, the first case of a choice, and the lowest level of a score. Call `->strict()` on the fake to throw an `UnscriptedQuestion` instead, which names any script with the same key and what differs:

```php
SystemOne::fake([FakeAnswer::yesNo($churn, 0.91)])->strict();
```

**Assertions** are on the fake and on the facade:

- `assertDecided(fn (Decision $decision, string $connection): bool => ...)`: some decision matched the callback.
- `assertDecidedTimes(2)`: exactly that many decisions.
- `assertNothingDecided()`.
- `assertAsked($question)`: some decision asked that question.

**What the fake also does:**

- It refuses whatever the real client would refuse before sending, such as a profile limit or a missing connection, and does not record it.
- Named connections work: `SystemOne::connection('jev')->decide()` is answered by the fake and recorded with the name `jev`. A remote connection needs no API key under the fake, because nothing is sent.
- `decideMany()` records each context as one decision. Its `ResultSet` has no `usage`, and every result's `meta` is empty (all null).

**Inject `ConnectionResolver`**, not `SystemOneManager`, in your app code, as `TicketTriage` does. The container resolves the interface to the fake once `SystemOne::fake()` has swapped it in. `SystemOneManager` is final and always stays the real manager. The `SystemOne` facade works under the fake too.

As with Laravel's other fakes, anything that resolved the interface before `fake()` was called keeps the real one. `system-one:try` uses the fake in an app's tests, and `system-one:health` always reads the real server.

## Events

Every decision a connection's client handles dispatches an event, for metrics and alerting:

- **`DecisionMade`**: `connection`, `model` (the model asked for, or null when the server routes), `questionKeys`, `result`, `meta`, `latencyMs` (null for a cached answer), `cached`, and `context`.
- **`DecisionFailed`**: `connection`, `model`, `questionKeys`, `exception` (a `SystemOneException`) and `context`. It is dispatched before the exception reaches you, for decisions refused before sending too. A connection that can't be built at all (`connection()` throws an `InvalidConfiguration`, such as a remote connection without a key) dispatches nothing: there is no client to report it.

```php
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (DecisionMade $event): void {
    Log::info('system-one decision', [
        'connection' => $event->connection,
        'questions' => $event->questionKeys,
        'latency_ms' => $event->latencyMs,
        'cached' => $event->cached,
    ]);
});

Event::listen(function (DecisionFailed $event): void {
    report($event->exception);
});
```

- **The context is left out by default**, because it may hold personal data: `context` is null unless you set `SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT=true`. The API key is never in an event.
- `SYSTEM_ONE_EVENTS=false` turns the events off.
- A batch dispatches one event per context. When the batch fails, every context gets a `DecisionFailed`.
- Listeners run synchronously, so an exception in one reaches the caller. Queue the listener to keep it apart.

## Caching

Identical decisions can be answered from a Laravel cache store instead of the server. Caching is off by default:

```dotenv
SYSTEM_ONE_CACHE=true
# A store from config/cache.php, such as redis or file; empty uses the default store.
SYSTEM_ONE_CACHE_STORE=
# Seconds; empty means an hour.
SYSTEM_ONE_CACHE_TTL=3600
```

**The cache key** is a SHA-256 of:

- the connection's name;
- the checkpoint revisions the server reports on `GET /health`;
- the whole request as it would be sent: the context, every question with its instructions, options and descriptions, the request controls, and the model.

Change any of them and the decision is sent again.

**How it works:**

- With the cache on, every decision first reads the server's `/health`. A cheap GET, it does no inference, and it means a new checkpoint revision never gets a stale answer.
- The cache is skipped, without a report, when `/health` lists no revisions or any loaded checkpoint has none, such as one loaded from a local path through `LAYA_EXTRA_MODELS`. Otherwise an answer is stored only when the checkpoint that gave it is one `/health` reported a revision for.
- A cached answer has no latency (`meta->latencyMs` is null), and its `DecisionMade` has `cached` set to true.
- A store that throws, a `/health` that fails, and an entry that won't decode are reported through Laravel's exception handler and skipped. They never fail a decision.
- Batches share entries with single decisions: each context is keyed as `decide()` would key it, and only the misses are sent.
- The setting applies to every connection. A host without `/health`, such as Jev, gets its failed read reported on every decision and is never cached, so leave the cache off if you mostly use such a host.

## Artisan commands

**`system-one:install [--mode=standalone|sail]`** sets up the local server (see [Installation](#installation)).

**`system-one:health [--connection=]`** reads the server's `GET /health` and prints its status, loaded checkpoints with their revisions, and device:

```text
$ php artisan system-one:health
Connection: local (http://localhost:8000)
Status: ok
Checkpoints: english (revision 55cf4c4ebb4ebe31b2550e8bdf3bd21b99753851)
Device: cpu
```

It exits 1 when the server is unreachable or unhealthy, and 2 when the connection can't be used: an unknown name, a config it refuses, or a remote connection without a key.

**`system-one:try`** asks questions about some text from the terminal and prints each answer and how the server routed it:

```bash
php artisan system-one:try "Refund the double charge or we cancel." --yes-no="Does the customer threaten to cancel?"
php artisan system-one:try --file=ticket.txt --choice="App\Enums\Department:Which department should handle this?" --score="App\Enums\Urgency:How urgent is this?"
cat ticket.txt | php artisan system-one:try --yes-no="Is this a complaint?" --json
php artisan system-one:try "Kündigen Sie meinen Vertrag." --yes-no="Does the customer want to cancel?" --connection=laya-hosted   # needs SYSTEM_ONE_LAYA_HOSTED_API_KEY
```

- The text comes from the argument, `--file`, or stdin.
- `--yes-no`, `--choice` and `--score` can each be repeated. `--choice` and `--score` name a backed enum of your app before a colon, then the instructions. The questions are keyed `q1`, `q2` and so on: the yes/no ones first, then the choices, then the scores.
- `--json` prints the answers and the routing as JSON. Errors go to stderr, so stdout stays valid JSON.
- `--connection` picks a connection other than the default.
- Exit codes: 0 when answered, 1 when the server couldn't be reached, refused the decision or answered something unreadable, and 2 when the input or the connection can't be used (nothing was sent).

## The local server

The server is [laya-serve](https://pypi.org/project/laya/) 0.4.8 in an image built from the package's `docker/laya/Dockerfile`. There is no published image: it is always built on your machine. It listens on `127.0.0.1:8000` only and keeps the weights in a Docker volume. By default it loads only the English checkpoint, pinned to the revision laya 0.4.8 reviewed.

Settings are read from the environment, and Compose also reads them from the `.env` next to the Compose file, which for the standalone file is your app's `.env`:

| Setting | Default | |
|---|---|---|
| `LAYA_MODELS` | `english` | Checkpoints loaded at start. `english,multilingual` adds the multilingual checkpoint for non-English text, which needs more memory. |
| `LAYA_DEFAULT_MODEL` | `english` | Where text with no language evidence goes. |
| `LAYA_HOST_PORT` | `8000` | The port on 127.0.0.1. Change `SYSTEM_ONE_URL` to match. |
| `LAYA_API_KEY` | none | Makes the server require a bearer key. Set `SYSTEM_ONE_API_KEY` to the same value. |
| `LAYA_THREADS` | torch's default | Caps the CPU threads torch uses. Keep it at or below your physical cores. |
| `LAYA_TOURNAMENT_GROUP` | none | Raises the choice option limit to 255. Set `SYSTEM_ONE_TOURNAMENT_GROUPING=true` to match. |
| `LAYA_EXTRA_MODELS` | none | JSON `{name: hub id or path}` of checkpoints of your own. List the names in `SYSTEM_ONE_MODELS`. |

Start, stop and follow it with Compose:

```bash
docker compose -f compose.system-one.yaml up -d --wait
docker compose -f compose.system-one.yaml logs -f laya
docker compose -f compose.system-one.yaml down        # the weights stay in the volume
```

For a multilingual server:

```bash
LAYA_MODELS=english,multilingual docker compose -f compose.system-one.yaml up -d --wait
```

**NVIDIA GPUs.** Add the package's CUDA override, which builds a separate image with a CUDA build of torch. It needs the NVIDIA Container Toolkit. `LAYA_TORCH_INDEX_URL` picks the wheel index that matches your driver:

```bash
docker compose -f compose.system-one.yaml -f vendor/earthlingollie/laravel-system-one-classification/docker/compose.cuda.yaml up -d --wait
```

## Troubleshooting

**The first start takes a long time.** It builds the image, about 1.7 GB with the CPU build of torch, and then downloads the English checkpoint, about 0.85 GB, from Hugging Face. No token is needed. Depending on your connection this can take from a few minutes to half an hour. `up --wait` blocks until the checkpoint is loaded, and the healthcheck gives it up to an hour. Watch it with `docker compose -f compose.system-one.yaml logs -f laya`. Later starts reuse the image and the weights and are quick. Until the checkpoint is loaded, `system-one:health` prints `none loaded yet`.

**Apple Silicon and other ARM64 machines run on the CPU only.** Docker on macOS has no access to the Apple GPU (no MPS inside a container), and the CUDA override is for NVIDIA only. CPU inference works, just more slowly than on a GPU. Give Docker Desktop at least 8 GB of memory (Settings → Resources), or the container may be killed while the checkpoint loads.

**Port 8000 is already in use.** `php artisan serve` also defaults to port 8000. Start the Laya server first, so that `serve` moves on to the next free port, or move the server: set `LAYA_HOST_PORT=8001` and `SYSTEM_ONE_URL=http://localhost:8001`.

**The first decision about non-English text is slow.** Text the server detects as non-English is routed to the multilingual checkpoint, which is downloaded and loaded on first use when it isn't in `LAYA_MODELS`. Preload it with `LAYA_MODELS=english,multilingual`, or pin `withModel('english')`.

**`HostUnavailable`.** The server isn't running, is still loading, or is busy: laya-serve answers 503 when it is saturated, and the client retries. Check `php artisan system-one:health`. If decisions time out on a slow machine, raise `SYSTEM_ONE_TIMEOUT`.

**`Could not resolve host: laya` with Sail.** `http://laya:8000` resolves only inside Sail's network, so run the commands and examples through Sail: `./vendor/bin/sail artisan system-one:health`, `./vendor/bin/sail php vendor/earthlingollie/laravel-system-one-classification/examples/yes-no.php`.

**`RejectedByModel` with HTTP 401.** The server runs with `LAYA_API_KEY`, and `SYSTEM_ONE_API_KEY` is missing or different.

**`InvalidConfiguration` naming `api_key`.** You named `laya-hosted` or `jev` without setting its key, so nothing was sent.

**Behind a TLS-inspecting proxy** the image build and the download fail on certificates. Build with `docker build --secret id=extra_ca,src=<ca.pem>`, and give the container the proxy's CA through `SSL_CERT_FILE` and `REQUESTS_CA_BUNDLE` in a Compose override.

## Runnable examples

[`examples/`](examples) holds one script per main use case, built on the enums above:

| Script | Shows |
|---|---|
| [`examples/yes-no.php`](examples/yes-no.php) | A yes/no question: `probability`, `isYes()`, `verdict()`. |
| [`examples/choice.php`](examples/choice.php) | A choice question: `value`, `answerConfidence`, raw `confidence`, `distribution`. |
| [`examples/score.php`](examples/score.php) | A score question: `value`, `expected`, `distribution`. |
| [`examples/thresholds-and-abstention.php`](examples/thresholds-and-abstention.php) | `withMinConfidence()`, `abstention`, and thresholds in PHP. |
| [`examples/request-controls.php`](examples/request-controls.php) | `Context::fields()`, the request controls, `withModel()` and `meta`. |
| [`examples/batch.php`](examples/batch.php) | `decideMany()` and `ResultSet`. |
| [`examples/connections.php`](examples/connections.php) | Named connections, and the remote ones refusing without a key. |
| [`examples/events-and-caching.php`](examples/events-and-caching.php) | `DecisionMade`, `DecisionFailed` and a cache hit. |
| [`examples/testing-with-the-fake.php`](examples/testing-with-the-fake.php) | `SystemOne::fake()`, `FakeAnswer`, `strict()` and the assertions. Needs no server. |

Run one from your app's root, where it uses your app's `.env` and config:

```bash
php vendor/earthlingollie/laravel-system-one-classification/examples/yes-no.php
```

or from a clone of this package, with the server from `composer laya:up`:

```bash
php examples/yes-no.php
```

Every example runs in CI: the fake one in the main test suite, the rest against the real Laya container. So they always match the code.

## Working on the package

```bash
composer install
composer laya:up            # build and start the local server; waits until it is ready
composer test               # Unit, Contract, Feature, Arch and Docs suites
composer test:integration   # against the server from laya:up
composer analyse            # PHPStan at max level
composer format             # Laravel Pint
composer test:coverage      # 100% of lines and branches of src/
composer mutate             # Infection
composer laya:down          # stop the server; the weights are kept
```

`LAYA_MODELS=english,multilingual composer laya:up` adds the multilingual checkpoint, and `LAYA_CUDA=1 composer laya:up` the CUDA override. `composer serve` runs the workbench app, a page per feature, on port 8010. The design, and what was built against it, is in [docs/PLAN.md](docs/PLAN.md).

## License

MIT. See [LICENSE](LICENSE).
