# Plan: typed PHP / Laravel client for System 1 decision models

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
  our own client, but its existence affects naming (see open question 1).
- **Not verified yet:** I couldn't read the Hugging Face model cards or
  laya-ai.com from the build environment, which blocks both hosts. The exact
  JSON field names below come from the PyPI README and still need checking
  against a running container in Phase 0.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Laravel layer   ServiceProvider · config/systemone.php ·     │
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
   local: laya-serve in Docker       jev: TypeSafe hosted API
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
   connections are (`local`, `jev`, and later `aws`). Every connection uses the
   same `HttpTransport`. The `Transport` interface stays as a small seam for two
   reasons: the testing fake, and a host that can't be reached over plain HTTP
   (for example a SageMaker endpoint that needs SigV4 signing; see open
   question 3). Until such a host exists, there is only one real
   implementation.

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

$result = SystemOne::decide($decision);                      // default connection
$result = SystemOne::connection('jev')->decide($decision);   // the same call against a different host

$result->get($department)->value;                            // Department::Billing (ChoiceAnswer<Department>)
$result->get($department)->probability;                      // P(chosen answer), comparable across models
$result->get($department)->probabilityOf(Department::Other); // float
$result->get($department)->distribution;                     // Distribution<Department>, ordered
$result->get($urgency)->value;                               // Urgency::High
$result->get($urgency)->expected;                            // 1.7 (expected ordinal level)
$result->get($churn)->probability;                           // 0.91
$result->get($churn)->isYes(threshold: 0.8);                 // bool
$result->get($churn)->verdict(yes: 0.8, no: 0.2);            // Verdict::Yes | No | Unsure
$result->get($department)->abstained();                      // true if below min_confidence
$result->meta;                                               // model / checkpoint used, routing reason, usage, latency
```

How the typing works:
- **Everything is immutable.** All value objects are `final readonly class`.
  Builder methods (`ask`, `withMinConfidence`, `withModel`) return a new
  instance.
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

- `config/systemone.php` has a default connection and named connections, each
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
  - `systemone:install` publishes the config and Compose files.
  - `systemone:health` checks the server's health and which checkpoints are
    loaded.
  - `systemone:try` runs a question set against some text from the CLI.

### Docker (part of the package)

```
docker/
  laya/Dockerfile        # python:3.12-slim, CPU torch wheel, pip install "laya[serve]==0.4.8", non-root user
  compose.yaml           # service `laya`: port 8000, HF cache volume, healthcheck, LAYA_REVISION=reviewed
  compose.cuda.yaml      # optional NVIDIA override
```

- Default: `LAYA_MODELS=english` and `LAYA_PRELOAD=1`, which keeps RAM use down.
  The multilingual checkpoint is opt-in.
- `systemone:install` either publishes `compose.yaml` into the app or adds a
  service to Laravel Sail's `docker-compose.yml` (see open question 10).
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
config/systemone.php
docker/
tests/{Unit,Contract,Integration}
```

## Phases

0. **Spike and verify (about 1 day).**
   - Run `laya-serve` in Compose.
   - Record real request and response fixtures for each question type, for
     batch calls, for abstention and for each error code.
   - Get Jev's OpenAPI spec (`api.typesafe.ai/openapi.json`) and compare it
     with what we recorded.
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
6. **Beyond local:** check the `jev` connection against the real API, and write
   a guide for the chosen AWS hosting option.

## Open architectural questions

1. **Package name and namespace.** `laya-php` is already a Packagist package
   name (`marcreichel/laya-php`). Our client also isn't tied to Laya, so I'd
   suggest a neutral name, for example `vendor/systemone` with the namespace
   `Vendor\SystemOne`. The plan uses `SystemOne` as a placeholder. What vendor
   name and package name should we use?
2. **Laravel-only, or a framework-agnostic core with a Laravel bridge?** A
   Laravel-only package can use the `Http` client, `Http::fake` and the
   container directly, and is less code. An agnostic core (PSR-18 and PSR-17)
   would keep it usable from other frameworks. *Recommendation: keep `src/`
   minus `Laravel/` free of framework imports (that costs little), but depend
   on `illuminate/http` for the transport and ship it as one package.*
3. **AWS hosting target.** Bedrock most likely can't host this model: it is an
   encoder and isn't one of the supported Custom Model Import architectures.
   Please verify that before we rule it out. Bedrock and SageMaker endpoints
   also need SigV4-signed `InvokeModel` or `InvokeEndpoint` calls, which plain
   HTTP can't make. The options:
   - **(a) ECS/Fargate or EKS running the same `laya-serve` image behind a
     load balancer.** It stays plain HTTP, so no adapter is needed.
     *Recommended.*
   - **(b) SageMaker.** Needs a `SigV4Transport`.
   - **(c) Jev enterprise only.**
4. **Confidence across models.** Laya and Jev compute `confidence` differently.
   *Recommendation: make `probability` (the probability of the chosen answer)
   the main number you threshold on, and expose the raw `confidence` as a
   value tagged with the profile that produced it.* Do you agree?
5. **Question identity.** Should questions have explicit string keys
   (`Question::choice('department', …)`, as in the plan) or keys generated from
   the handle? Explicit keys are stable in logs, caches and fixtures, but you
   have to name each question.
6. **Enum conventions.**
   - Should descriptions come from a `#[Describe]` attribute (as in the plan)
     or from an interface method such as `describe(): string`? An interface
     method allows translation.
   - Should score order follow the int backing value (as in the plan) or the
     order the cases are declared in?
7. **Abstention typing.** When the model abstains, should `value` be nullable
   (`?TEnum`), which is honest but forces a null check on every read? Or should
   `value` always hold the model's best guess, with `abstained()` as a separate
   flag? The plan assumes the second.
8. **Declarative "decision classes."** As well as question handles, should we
   support a readonly DTO whose constructor parameters carry `#[Ask]`
   attributes and get filled in from the result? It's ergonomic but adds
   reflection. *Recommendation: handles in v1, consider DTOs later.*
9. **Minimum PHP and Laravel versions.** One option is PHP 8.3 with Laravel
   12 and 13, for the widest reach. The other is PHP 8.4 with Laravel 13, which
   allows property hooks and asymmetric visibility and keeps the value objects
   simpler.
10. **How the local model is provisioned in a consuming app.**
    - **(a)** Publish a standalone `compose.yaml`.
    - **(b)** Add a service to Laravel Sail.
    - **(c)** Both, chosen at `systemone:install`.

    Two related decisions:
    - Do we build the image locally from our Dockerfile, or publish a prebuilt
      image to GHCR? A prebuilt image gives faster onboarding but adds a
      release pipeline, and the image is several GB.
    - Which checkpoints are loaded by default? English only uses less RAM.
      Adding multilingual covers non-English input.
11. **Sync, batch and queues.** Is a synchronous `decide()` plus `decideMany()`
    enough for v1, or do we want queued or async dispatch, or concurrent
    requests through `Http::pool`, from the start?
12. **Caching and data handling.** Should result caching be on by default? Can
    context text be logged or sent to events (PII)? For the Jev connection, is
    sending data to a third party acceptable?
