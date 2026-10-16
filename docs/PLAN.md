# Plan: `laravel-system-one-classification`

A typed Laravel client for System 1 decision models (Laya, Jev and compatible models).

## Decisions so far

| # | Topic | Decision |
|---|---|---|
| 1 | Package name | `laravel-system-one-classification`. The plan uses `earthlingollie/laravel-system-one-classification`, namespace `EarthlingOllie\SystemOneClassification` and facade `SystemOne`; the vendor is provisional. |
| 2 | AWS / Bedrock | Out of scope for now. There is no AWS connection or SigV4 transport in v1. |
| 3 | Confidence | Every answer exposes both `confidence` values. `answerConfidence` (the probability of the reported answer) is what you threshold on, because it can be compared across models. The raw `confidence` is kept and tagged with the model profile that produced it. |
| 4 | Local provisioning | A standalone `compose.yaml` is the primary path, with an optional Laravel Sail service. Both are offered by `system-one:install`. |
| 5 | PHP version | PHP 8.4 minimum, with Laravel 13. Value objects are kept as simple as possible (see Quality bar). |
| 6 | Vendor | `earthlingollie`. |
| 7 | Framework coupling | One Laravel package that depends on `illuminate/http`. Everything outside `src/Laravel/` stays free of other framework imports. |
| 8 | Question identity | Explicit string keys (`Question::choice('department', …)`). The question object is the handle you read results with. |
| 9 | Enum conventions | Descriptions come from `#[Describe]` on each case, with an optional `DescribesCases` interface for translated text. Score order follows the int backing value. |
| 10 | Abstention | `value` always holds the model's best guess. A separate `abstained` flag marks low-confidence answers. |
| 11 | Decision classes | Question handles only in v1. Attribute-driven DTOs may come later. |
| 12 | Local image | Built on the dev machine from our Dockerfile; there is no published image. Loads only the English checkpoint by default; multilingual is opt-in. |
| 13 | Sync and batch | `decide()` and `decideMany()` (the batch endpoint) are synchronous. Queued and concurrent dispatch come later. |
| 14 | Caching and data | Caching is off by default. Context text is never logged or put in events by default. Remote connections (`laya-hosted`, `jev`) are used only when they are configured explicitly with a key; the default connection is always `local`. |
| 15 | Quality | All code is immutable and fully tested, as defined in the Quality bar section below. |

## Goal

A Laravel package that gives PHP code a fully typed, immutable way to ask System 1
decision models (Laya, Jev and compatible models) typed questions about a piece of
context and get typed answers back. The package also ships a Docker Compose
setup that runs Laya locally, so a dev machine can use it with no other setup.

## What we found during research

These findings shape the design, so they come first.

- **Laya** ([PyPI `laya` 0.4.8](https://pypi.org/project/laya/), Apache-2.0,
  [GitHub](https://github.com/NandhaKishorM/laya)) is an encoder-only,
  non-autoregressive decision model. It comes as three checkpoints, and a router
  picks one for each request:

  | checkpoint | encoder | params | context |
  |---|---|---|---|
  | `english` (`convaiinnovations/laya`) | ModernBERT-large | 421M | 512 |
  | `multilingual` | mmBERT-base | 322M | 1024 (up to 8192) |
  | `typed-decisions` | ModernBERT-large | 421M | 1024 |

- **It has three question types:**
  - `choice`: pick one label from a set of labelled criteria.
  - `score`: ordinal levels, where every level needs a description.
  - `noul`: a yes/no statement check that returns P(true).

  Each answer carries a `confidence` value and an `answer_confidence` value, and
  `choice` and `score` answers also carry the full probability distribution. The
  server can also abstain when an answer falls below `min_confidence`.
- **`laya-serve` (`pip install "laya[serve]"`) speaks the same wire protocol as
  TypeSafe's hosted Jev API.** It serves `POST /v1/systemone` and
  `POST /v1/systemone/batch` (up to 64 states per call), with optional bearer
  auth (`LAYA_API_KEY`). Laya says its answer payload has the same schema as
  Jev's. **So one HTTP client can reach both Jev and self-hosted Laya.** The host
  becomes configuration (a base URL, a key and a model name), not a separate
  adapter.
- **Known differences between Laya and Jev**, which our "model profile" has to
  capture:
  - Laya allows at most about 100 choice options per question and a 192–256
    token budget for all the options together. Jev allows 255 options.
  - Laya requires a description for every score level.
  - `confidence` is computed differently on each (Laya uses 1 − normalised
    entropy). A threshold from one does not carry over to the other.
- **Docker:** upstream ships a CPU Compose quickstart and a CUDA override. It
  needs about 8 GB RAM and 10 GB disk. Weights download from Hugging Face on
  first run (no token needed) and are kept in a volume. Apple Silicon and ARM64
  run on CPU only, because there is no MPS inside Docker. Useful settings:
  - `LAYA_MODELS` and `LAYA_PRELOAD` choose which checkpoints to load at start.
  - `LAYA_REVISION=reviewed` pins the checkpoint commits.
  - `LAYA_DEFAULT_MODEL` sets the checkpoint used when the language is unclear.
  - `OMP_NUM_THREADS` caps CPU threads.
- **Prior art:** [`marcreichel/laya-php`](https://github.com/marcreichel/laya-php)
  (Packagist, PHP 8.4+, Laravel 13+) already targets `laya-serve`. We are writing
  our own client under a different name (`laravel-system-one-classification`).
- **Response format confirmed** on [laya-ai.com/laya-api](https://laya-ai.com/laya-api):

  ```json
  {
    "model": "laya-rl-agent",
    "answers": {
      "queue":   {"type": "choice", "choice": "billing",
                  "probabilities": {"billing": 0.9281, "tech": 0.0412, "other": 0.0307},
                  "confidence": 0.4534, "answer_confidence": 0.9281},
      "urgency": {"type": "score", "score": 2.6389,
                  "legend": {"0": "calm", "1": "firm", "2": "angry", "3": "furious"},
                  "probabilities": {"0": 0.0099, "1": 0.0713, "2": 0.536, "3": 0.3828}},
      "refund":  {"type": "noul", "noul": 0.97}
    },
    "usage":   {"input_tokens": 74, "output_tokens": 0},
    "routing": {"model": "english", "reason": "English Latin text"}
  }
  ```

  The request takes `state` (a string or a JSON object) and `questions`, plus
  these optional fields: `model`, `lang`, `lang_guess`, `min_confidence`,
  `max_len`, `head_max_len` and `task`. Answers below `min_confidence` come
  back marked as low confidence. The README names the fields `abstention` and
  `abstention_threshold`; Phase 0 confirms the exact spelling.
- **There is a hosted Laya API at `https://api.laya-ai.com/v1/systemone`.** It
  is run by laya-ai.com, a third party, not by Convai, and uses the same
  protocol with a bearer key, at $0.05 per 1,000 calls. That makes three hosts
  behind one client: local `laya-serve`, hosted Laya, and Jev.
- **More server settings:**
  - `LAYA_TOURNAMENT_GROUP` raises the choice-option cap from 100 to 255.
  - `LAYA_JEV_STRICT=1` returns only the fields in Jev's response format.
  - The Hugging Face checkpoint (`convaiinnovations/laya`, Apache-2.0) is at
    revision `7b928d8`.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Laravel layer   ServiceProvider · config/system-one.php ·     │
│                 Facade · Fake · artisan commands · events    │
├─────────────────────────────────────────────────────────────┤
│ Client          DecisionClient::decide(Decision): Result     │
│                 DecisionClient::decideMany(...): ResultSet   │
├───────────────────────────────┬─────────────────────────────┤
│ Model profile                 │ Wire codec                  │
│ LayaProfile / JevProfile      │ SystemOneCodec              │
│ - option & level limits       │ Decision → /v1/systemone    │
│ - confidence semantics        │ JSON → Result               │
│ - model id / checkpoint names │                             │
├───────────────────────────────┴─────────────────────────────┤
│ Transport (seam)   HttpTransport (Laravel Http) · Fake       │
├─────────────────────────────────────────────────────────────┤
│ Connection (config)  base_url · api_key · model · profile ·  │
│                      timeout · retries                       │
└─────────────────────────────────────────────────────────────┘
          │                                  │
   local: laya-serve in Docker   laya-hosted: api.laya-ai.com   jev: TypeSafe
```

### The two abstractions you asked for

1. **Model** → a `ModelProfile`. Both models share one protocol, so a profile
   doesn't encode requests. It describes the model instead: its limits (option
   counts, whether score levels need descriptions), how to read `confidence`,
   and which `model` values are valid. Before sending, the client checks a
   `Decision` against the profile, so a request that is too large fails in PHP
   with a clear exception instead of a 413 or 422 from the server. To switch
   models, change the profile and the model name in config.
2. **Host** → a named `Connection` in config, set up the way Laravel database
   connections are (`local`, `laya-hosted`, `jev`). Every connection uses the
   same `HttpTransport`. The `Transport` interface exists only so the testing
   fake can replace HTTP. AWS is out of scope (decision 2).

### Proposed public API

```php
enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';
    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';
    #[Describe('everything else')]
    case Other = 'other';
}

enum Urgency: int            // score: ordinal levels, in backing-value order
{
    #[Describe('can wait')]  case Low = 0;
    #[Describe('soon')]      case Medium = 1;
    #[Describe('blocking')]  case High = 2;
}

$department = Question::choice('department', Department::class, 'Which department should handle this?');
$urgency    = Question::score('urgency', Urgency::class, 'How urgent is this?');
$churn      = Question::yesNo('churn', 'Does the customer threaten to cancel?');

$decision = Decision::about(Context::text($ticket->body))   // or Context::fields(['subject' => …, 'body' => …])
    ->ask($department, $urgency, $churn)
    ->withMinConfidence(0.6);                                // returns a new instance; Decision is immutable

$result = SystemOne::decide($decision);                      // default connection (local)
$result = SystemOne::connection('jev')->decide($decision);   // the same call against a different host

$result->get($department)->value;                            // Department::Billing (ChoiceAnswer<Department>)
$result->get($department)->answerConfidence;                 // P(reported answer): threshold on this
$result->get($department)->confidence;                       // Confidence { value, profile } (raw model-specific value)
$result->get($department)->isConfident(0.8);                 // shorthand for answerConfidence >= 0.8
$result->get($department)->probabilityOf(Department::Other); // float
$result->get($department)->distribution;                     // Distribution<Department>, ordered
$result->get($urgency)->value;                               // Urgency::High
$result->get($urgency)->expected;                            // 1.7 (expected ordinal level)
$result->get($churn)->probability;                           // 0.91
$result->get($churn)->isYes(threshold: 0.8);                 // bool
$result->get($churn)->verdict(yes: 0.8, no: 0.2);            // Verdict::Yes | No | Unsure
$result->get($department)->abstained;                        // true if below min_confidence
$result->meta;                                               // model / checkpoint used, routing reason, usage, latency
```

How the typing works:
- **Everything is immutable.** Every value object is a `final readonly class`
  with promoted public properties. Derived values are methods
  (`isConfident(0.8)`, `probabilityOf(...)`). Builder methods (`ask`,
  `withMinConfidence`, `withModel`) return a new instance. See the Quality bar
  for why this uses `readonly` rather than property hooks.
- **Typed results without casting, through generics.** The answer type is a
  PHPStan or Psalm generic. `ChoiceQuestion<TEnum of BackedEnum>` extends
  `Question<ChoiceAnswer<TEnum>>`, and `Result::get(Question<TAnswer>): TAnswer`
  returns that answer type. Static analysis then knows that
  `$result->get($department)->value` is a `Department`.
- **Questions are handles.** You keep the question object and pass it back to
  `get()`. The string key exists only so the wire format and logs stay stable.
- **Enums drive the options.** `choice` and `score` take a `BackedEnum`
  class-string. Labels come from backing values, and descriptions come from a
  `#[Describe]` attribute on each case. A `score` enum must describe every case,
  because Laya requires it.
- **Typed errors.**
  - `InvalidDecision`: a profile limit was broken, caught before sending.
  - `HostUnavailable`: transport errors or 503 responses.
  - `RejectedByModel`: the server returned 4xx.
  - `MalformedResponse`: the response JSON didn't match what we expected.

### Laravel integration

- `config/system-one.php` has a default connection and named connections, each
  with its own profile.
- A singleton is bound in the container and exposed through a `SystemOne`
  facade.
- `SystemOne::fake([...])` returns scripted answers and lets tests assert on the
  decisions that were sent.
- Events: `DecisionMade` and `DecisionFailed`. Logging the context text is off
  by default, because it may contain PII.
- Optional result caching through Laravel's cache, keyed on the decision, the
  model and the checkpoint revision.
- Artisan commands:
  - `system-one:install` publishes the config and Compose files.
  - `system-one:health` checks the server's health and which checkpoints are
    loaded.
  - `system-one:try` runs a question set against some text from the CLI.

### Docker (part of the package)

```
docker/
  laya/Dockerfile        # python:3.12-slim, CPU torch wheel, pip install "laya[serve]==0.4.8", non-root user
  compose.yaml           # service `laya`: port 8000, HF cache volume, healthcheck, LAYA_REVISION=reviewed
  compose.cuda.yaml      # optional NVIDIA override
```

- Default: `LAYA_MODELS=english` and `LAYA_PRELOAD=1`, which keeps RAM use down.
  The multilingual checkpoint is opt-in.
- `system-one:install` asks which to set up:
  - **Standalone** (the default): publishes `compose.system-one.yaml` into the
    app and sets `SYSTEM_ONE_URL=http://localhost:8000` in `.env`.
  - **Sail**: adds a `laya` service to the app's Sail `docker-compose.yml` and
    sets `SYSTEM_ONE_URL=http://laya:8000`, so it is reachable from the
    `laravel.test` container.
- The repo also has its own dev Compose file, which runs a PHP container next to
  `laya` for integration tests. CI runs the same setup with the HF cache volume
  cached between runs.

### Package layout

```
src/
  Domain/        Context, Decision, Question/{Choice,Score,YesNo}Question, Describe attribute
  Result/        Result, ResultSet, Answer/{Choice,Score,YesNo}Answer, Distribution, Verdict, Meta
  Model/         ModelProfile, LayaProfile, JevProfile
  Protocol/      SystemOneCodec (request/response mapping)
  Transport/     Transport, HttpTransport, FakeTransport
  Laravel/       SystemOneServiceProvider, Facades/SystemOne, Commands/*, Events/*, Testing/Fake
  Exceptions/
config/system-one.php
docker/
tests/{Unit,Contract,Integration}
```

## Quality bar

### Immutability

- **Every value object, answer, result, profile, connection config and the
  client itself is a `final readonly class`.** The engine then rejects any
  write after construction. This is why we don't use asymmetric visibility
  (`public private(set)`) or property hooks: `private(set)` still allows the
  class to change its own state, and PHP 8.4 doesn't allow hooks on readonly
  properties. Derived values are plain methods instead.
- Collections (questions in a `Decision`, answers in a `Result`, a
  `Distribution`) are built once and exposed only through read methods. They
  never expose a mutable array by reference.
- Every `with*()` method returns a new instance. Tests prove the original is
  unchanged.
- Pest architecture tests enforce all of this: every class in the domain,
  result, model and protocol namespaces must be `final` and `readonly`, and
  the core namespaces must not import `Illuminate\*`.
- The only stateful pieces are Laravel singletons and the testing fake, which
  records the decisions sent to it. Both live under `src/Laravel/`.

### Testing

- **Tools:** Pest 3 on Orchestra Testbench (Laravel 13, PHP 8.4), PHPStan at
  max level with strict rules, Laravel Pint, and Infection for mutation
  testing.
- **CI gates, all required on every pull request:**
  - 100% line and branch coverage of `src/`.
  - Infection MSI ≥ 90% (covered-code MSI 100%).
  - PHPStan max with no baseline.
  - Pint.
  - Architecture tests.
- **Test layers:**
  - **Unit:** every value object, validation rule and error path.
  - **Type inference:** PHPStan `assertType()` files prove
    `$result->get($q)->value` comes out as the right enum without casting.
  - **Contract:** the codec is checked against fixtures recorded from a real
    `laya-serve`, and request bodies are checked against Jev's OpenAPI schema.
  - **Feature (Testbench):** the service provider, config, facade, fake,
    commands and events.
  - **Integration:** a separate suite runs against the Docker container in CI.
    It is tagged so local runs can skip it when Docker isn't running.
- Each ticket lands with its own tests, and nothing merges with a gate
  failing.

## Phases

0. **Spike and verify (about 1 day).**
   - Run `laya-serve` in Compose.
   - Record real request and response fixtures for each question type, for
     batch calls, for abstention and for each error code.
   - Get Jev's OpenAPI spec (`api.typesafe.ai/openapi.json`) and compare it
     with what we recorded. Confirm whether `noul` answers include
     `answer_confidence`, and how low-confidence answers are marked.
   - Measure CPU latency and RAM on an Intel dev machine and an Apple Silicon
     dev machine.
1. **Docker:** the Dockerfile, the Compose file, a healthcheck, a pinned
   version, and composer scripts (`composer laya:up` and `laya:down`).
2. **Domain core:** value objects, generics and validation. Unit tests, with
   PHPStan at max level and type-inference tests.
3. **Protocol and transport:**
   - `SystemOneCodec`, the profiles and `HttpTransport`.
   - Contract tests against the recorded fixtures and the Jev OpenAPI schema.
   - Integration tests against the container.
4. **Laravel layer:** the provider, config, facade, fake, commands, events,
   install flow and Sail option.
5. **Ergonomics:** `decideMany` (the batch endpoint), caching, retries and
   timeouts, and docs and examples.
6. **Remote hosts:** check the `laya-hosted` and `jev` connections against
   the real APIs.

## Open architectural questions

None. Everything raised so far is settled in the decisions table. New
questions get added here as they come up.
