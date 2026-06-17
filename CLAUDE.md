# parse-sdk

The **Client SDK** for Parse for Artisans: the Composer package a Laravel developer installs
with `composer require parseforartisans/laravel`. It exposes the `Parse` facade, submits files
to the SaaS API, tracks each submission in a local `parse_requests` table, and delivers the
result through a `ParseCompleted` / `ParseFailed` event.

This repo is **only the SDK package**. The SaaS app (`../app`), the Modal parse backend
(`../modal`), and the architecture/docs (`../architecture`) live in sibling repos under
`~/Herd/parseforartisans/`. The SDK talks to nothing but the SaaS HTTP API.

## Source-of-truth contracts (do not re-derive, follow them)

These are frozen. When building the SDK, read them first and match them exactly. If the SDK
needs something they do not specify, raise it rather than inventing wire behavior.

- **`../architecture/sdk-saas-contract.md`** is the SDK to SaaS wire contract: endpoints,
  request/response payloads, error taxonomy, signing, the options object. This is the single
  most load-bearing document for this repo.
- **`../architecture/architecture.md`** is the system overview: the parse flow, storage modes
  (BYO vs managed), delivery modes (webhook vs poll), and the `parse_requests` column list.
- **`../architecture/CLAUDE.md`** is the high-level overview and the resolved decisions.
- **`../IMPLEMENTATION_PLAN.md`** is the milestone plan. The SDK is **Milestone 3**; M1 (API
  keys) and M2 (submit/status/storage/Modal) on the SaaS side are its prerequisites.
- **`../app/resources/views/marketing/docs/markdown/`** is the developer-facing documentation
  (served at `parseforartisans.com/docs`). The SDK's public surface must match it verbatim:
  method names, signatures, config keys, command names, event names. If code and docs disagree,
  the docs are the published promise. Treat them as the acceptance criteria.

Base URL: `https://parseforartisans.com`. All API paths are under `/api/v1`.

## What to build (the public surface)

Everything below is specified in the contracts above. This is the checklist, not the spec.

- **Facade `ParseForArtisans\Facades\Parse`** with builder entry points: `Parse::file($path)`,
  `Parse::disk($disk)->file(...)`, `Parse::files([...])` (batch), `Parse::url($url)`,
  `Parse::find($id)`.
- **Fluent builder** chaining `->for($model)`, `->withMeta([...])`, `->ocr(true)`,
  `->ocrLanguage('spa')`, `->pages('1-20')`, `->frontmatter(true)`, `->to($path)`,
  `->disk($disk)`, terminated by `->parse()`. `->parse()` is a fast inline HTTP call, not a
  queued job. Batch returns a collection of `ParseRequest`.
- **`ParseForArtisans\Models\ParseRequest`** Eloquent model plus its migration, columns per
  `architecture.md` (`id` uuid, `disk`, `source_path`, `output_path`, `status`, `page_count`,
  `credits_used`, `error`, `parsable_type`, `parsable_id`, `meta` json, `created_at`,
  `started_at`, `completed_at`, `duration_ms`). Handle methods: `->id`, `->status()` (reads the
  local row, no API call), `->markdown()` (resolves by storage mode), `->wait()` (synchronous
  poll for CLI/tinker only). `->parsable` resolves the morph set by `->for()`.
- **Events** `ParseForArtisans\Events\ParseCompleted` and `ParseFailed`, each carrying the
  `ParseRequest` as `$event->request`. Auto-discovered on Laravel 12/13.
- **Listener stubs** `HandleParsedDocument` and `HandleFailedParse`, published to
  `app/Listeners/` by the install command.
- **Webhook route** (webhook delivery): verifies `X-Parse-Signature` against
  `PARSE_WEBHOOK_SECRET`, enforces the 5-minute replay window, looks up the row by `id`, is
  idempotent on already-terminal rows, then fires the event.
- **Poll job** `poll-parse-status` (poll delivery): self-releasing, reads `GET /api/v1/parse/{id}`,
  `release()`s while pending, fires the event on a terminal status, has a capped TTL that fires
  `ParseFailed` so the event always eventually arrives.
- **Artisan commands** `parse:install`, `parse:ping`, `parse:file` (uses `->wait()` internally,
  supports `--save=out.md`).
- **Exceptions** `ParseForArtisans\Exceptions\ParseException` (synchronous submit errors),
  `ParseFailedException` and `ParseTimeoutException` (thrown by `->wait()`).
- **Config** `config/parse.php`: `disk` (`env('PARSE_DISK')`, unset = managed bucket), `output`
  (`'parsed'`), `delivery` (`env('PARSE_DELIVERY', 'auto')`). API key reads from
  `env('PARSE_API_KEY')`, signing secret from `env('PARSE_WEBHOOK_SECRET')`.

## Invariants the SDK must preserve

These come straight from the contract and are easy to get wrong:

1. **One async contract.** `->parse()` submits and returns a handle. There is no blocking string
   return. Results arrive by event. `->wait()` is the only synchronous path and is for CLI/tinker,
   never a web request.
2. **The SDK mints the `id` (uuid) and owns correlation.** `->for()` and `->withMeta()` never
   leave the SDK; the SaaS only echoes the `id`. The `id` is account-scoped server-side.
3. **The SDK and SaaS exchange only ids and URLs, never the markdown body.** The event carries
   metadata; `->markdown()` resolves the body separately.
4. **Storage mode only changes who presigns and how `->markdown()` reads.** BYO (`parse.disk`
   set): the SDK presigns GET+PUT on the customer disk, submits JSON, `->markdown()` reads the
   disk directly. Managed (`parse.disk` unset): the SDK reads bytes from the default disk
   (`config('filesystems.default')`), submits multipart, `->markdown()` calls
   `GET /api/v1/parse/{id}/markdown`.
5. **Validation is server-side.** The SDK does not pre-check extensions or option/format
   compatibility. It submits; the SaaS validates and rejects synchronously (`unsupported_type`,
   `unsupported_option`), which `->parse()` throws as `ParseException`.
6. **Delivery `auto` resolves by `APP_ENV`:** `poll` when `local`, `webhook` everywhere else.
   The two paths never run together.
7. **Two error channels.** Submission problems are synchronous (`ParseException` from
   `->parse()`). Parse-time problems are asynchronous (`ParseFailed` event with
   `$request->error`). Keep the split.

## How to build a Laravel package (the idiomatic approach)

Researched June 2026. This package follows current Laravel package conventions.

### Recommended foundation: `spatie/laravel-package-tools`

It is the de-facto standard for Laravel packages (130M+ installs) and a thin layer over the
native service-provider registration the framework already documents
(`laravel.com/docs/13.x/packages`). It removes the boilerplate for publishing config,
migrations, routes, commands, and views. Plain native registration is equally valid; if we ever
want zero extra dependencies, the structure below maps one-to-one onto manual
`mergeConfigFrom` / `publishes` / `loadMigrationsFrom` / `loadRoutesFrom` calls.

The service provider extends `Spatie\LaravelPackageTools\PackageServiceProvider` and configures
the package in one method:

```php
public function configurePackage(Package $package): void
{
    $package
        ->name('parse')                       // sets publish tags: parse-config, parse-migrations
        ->hasConfigFile('parse')              // config/parse.php
        ->hasMigration('create_parse_requests_table')
        ->hasRoute('webhook')                 // routes/webhook.php (the signed callback)
        ->hasCommands([PingCommand::class, FileCommand::class])
        ->hasInstallCommand(function ($command) {
            $command
                ->publishConfigFile()
                ->publishMigrations()
                ->askToRunMigrations();
            // plus: publish the listener stubs to app/Listeners (custom publish group)
        });
}
```

Note: the composer package name is `parseforartisans/laravel`, but `->name('parse')` is what
drives the publish tags and the `parse:install` command name. The `parse:install` step in the
docs does three things: publish `config/parse.php`, publish the migration, and drop the
`HandleParsedDocument` / `HandleFailedParse` listener stubs into `app/Listeners/`. The stub copy
is a custom publish group wired through the install command's `startWith`/`endWith` hooks.

Lifecycle hooks (`registeringPackage`, `packageRegistered`, `bootingPackage`, `packageBooted`)
bind the singletons (the HTTP client, the `Parse` manager) and register the facade.

### Conventions to follow

- **Composer package name:** `parseforartisans/laravel`. **PSR-4 namespace:** `ParseForArtisans\`
  mapped to `src/`. Tests under `ParseForArtisans\Tests\` mapped to `tests/`.
- **Auto-discovery:** declare the service provider (and the `Parse` facade alias) under
  `extra.laravel.providers` / `extra.laravel.aliases` in `composer.json` so it registers with no
  manual step on install.
- **Versions:** target **Laravel 12 and 13** and **PHP `^8.3`** (the SaaS app is `php ^8.3` /
  Laravel `^13.7`; `^8.3` keeps the SDK installable in a wider range of customer apps). Express
  the constraints as ranges in `composer.json` (`"illuminate/contracts": "^12.0|^13.0"`,
  `"php": "^8.3"`).
- **Dependencies:** keep them minimal. This ships into customer apps, so every dependency is
  their problem too. Use the framework's HTTP client (`Illuminate\Support\Facades\Http`), not a
  bundled Guzzle wrapper.
- **Config:** ship sane defaults and read secrets from env, never commit secrets. Use
  `mergeConfigFrom` semantics (handled by `hasConfigFile`) so a partial published config still
  works.
- **Migrations:** publish them; let the host app run them. Use the `publishesMigrations` behavior
  so the timestamp is stamped at publish time.

### Suggested directory layout

```
parse-sdk/
├── composer.json                       # name, psr-4, laravel auto-discovery, version ranges
├── config/
│   └── parse.php
├── database/
│   └── migrations/
│       └── create_parse_requests_table.php.stub
├── routes/
│   └── webhook.php
├── resources/
│   └── stubs/
│       ├── HandleParsedDocument.php.stub
│       └── HandleFailedParse.php.stub
├── src/
│   ├── ParseServiceProvider.php
│   ├── Parse.php                       # the manager/builder behind the facade
│   ├── PendingParse.php                # the fluent builder (->ocr(), ->pages(), ->parse())
│   ├── Facades/Parse.php
│   ├── Models/ParseRequest.php
│   ├── Events/{ParseCompleted,ParseFailed}.php
│   ├── Jobs/PollParseStatus.php
│   ├── Http/{SubmitsToApi, VerifiesSignature, WebhookController}.php
│   ├── Commands/{InstallCommand,PingCommand,FileCommand}.php
│   └── Exceptions/{ParseException,ParseFailedException,ParseTimeoutException}.php
└── tests/
    ├── Pest.php
    ├── TestCase.php                    # extends Orchestra\Testbench\TestCase
    └── Feature/
```

### Testing

- **Orchestra Testbench** boots a minimal Laravel app so package code can be tested as if
  installed. The base `TestCase` registers `ParseServiceProvider` and sets test config.
- **Pest** for the test suite (matches the SaaS app's choice).
- The SDK's own tests, and the testing story we document for consumers (decision G4: no bespoke
  fake), lean on framework built-ins: `Http::fake()` for the submit/status calls,
  `Storage::fake()` for the BYO `->markdown()` path, and dispatching `ParseCompleted` /
  `ParseFailed` directly to drive a listener. Do not build a `Parse::fake()` in v1.
- Cover: builder-to-payload mapping (options object, BYO vs managed shape), `->parse()` throwing
  `ParseException` on each submit error type, signature verification (valid, tampered, expired),
  poll job releasing then firing the event, `->wait()` success/failure/timeout, and
  `->markdown()` resolving correctly per mode.

## Working agreements

- **Match the contracts and the published docs exactly.** They are frozen and customer-facing.
  When something is underspecified, ask rather than guess at wire behavior.
- **Run `vendor/bin/pint` before finalizing** any PHP change (the SaaS app uses Pint; keep the
  SDK consistent).
- **Every change is tested.** Add or update a Pest test and run the affected tests.
- **Prose style:** no em dashes, no cute phrasing. Plain and direct.
</content>
</invoke>
