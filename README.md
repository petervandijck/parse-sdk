# Parse for Artisans (Laravel SDK)

Parse documents to Markdown from Laravel. Install the package, set an API key, and call
`Parse::file('contract.pdf')->parse()`; the result arrives in a `ParseCompleted` event. This is
the client SDK for [parseforartisans.com](https://parseforartisans.com).

> **Pre-release.** This package is under active development. The install and `parse:ping` paths
> work against the live API today. The full parse round trip (`->parse()` to a `ParseCompleted`
> event) is built and covered by tests but is verified live once the SaaS parse endpoints ship.
> See [Current status](#current-status).

## Requirements

- PHP `^8.3`
- Laravel 12 or 13

## Installation

```bash
composer require parseforartisans/laravel
```

Add your credentials to `.env`:

```env
PARSE_API_KEY=pfa_...
PARSE_WEBHOOK_SECRET=whsec_...
```

Run the installer. It publishes `config/parse.php`, the `parse_requests` migration, and two
event listeners into `app/Listeners/`, then offers to run the migration:

```bash
php artisan parse:install
```

Confirm the key works and the service is reachable:

```bash
php artisan parse:ping
```

```
✔ Connected to parseforartisans.com
✔ API key valid (plan: free)
✔ Ready to parse
```

## Usage

```php
use ParseForArtisans\Facades\Parse;

$parse = Parse::file('contracts/foo.pdf')->parse();   // default disk
$parse->id;        // uuid
$parse->status();  // 'pending'
```

Options chain before `->parse()`:

```php
Parse::file('contracts/foo.pdf')
    ->for($document)         // associate with one of your models, handed back in the event
    ->withMeta(['tenant_id' => $tenant->id])
    ->ocr(true)
    ->ocrLanguage('spa')
    ->pages('1-20')
    ->frontmatter(true)
    ->to('out/foo.md')
    ->parse();
```

Handle the result in the published listener (`app/Listeners/HandleParsedDocument.php`):

```php
public function handle(ParseCompleted $event): void
{
    $markdown = $event->request->markdown();
    $document = $event->request->parsable;   // the model from ->for(), or null
}
```

From the command line:

```bash
php artisan parse:file contracts/foo.pdf            # submit, wait, print the Markdown
php artisan parse:file contracts/foo.pdf --save=out.md
```

The full developer documentation lives at
[parseforartisans.com/docs](https://parseforartisans.com/docs).

## Configuration

`config/parse.php`:

```php
'disk'     => env('PARSE_DISK'),               // your bucket. Unset uses our managed dev bucket.
'output'   => 'parsed',                        // prefix where Markdown is written
'delivery' => env('PARSE_DELIVERY', 'auto'),   // auto | webhook | poll
```

`delivery=auto` polls on `APP_ENV=local` and uses webhooks everywhere else. Local poll delivery
rides the queue worker that Laravel's `composer run dev` already runs.

## Testing your own code

This package uses Laravel's built-ins, so no bespoke fake is needed:

- `Http::fake()` stubs the submit and status calls.
- `Storage::fake()` backs the file reads.
- Dispatch `ParseCompleted` / `ParseFailed` directly to drive your listeners.

## Local manual test (against the staging or local SaaS)

For testing the SDK from a separate Laravel app while it is not yet on Packagist, require it from
a local path. In the consumer app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../parse-sdk" }
],
"require": {
    "parseforartisans/laravel": "*"
}
```

Then:

```bash
composer require parseforartisans/laravel       # symlinks the local package
```

Point the SDK at the running SaaS and set a real key in the consumer app's `.env`:

```env
PARSE_BASE_URL=https://your-saas-host        # the Herd URL of the SaaS app, or php artisan serve
PARSE_API_KEY=pfa_...                        # a key minted in the dashboard
```

Then run, in order:

1. `php artisan parse:install` and confirm `config/parse.php`, the migration, and
   `app/Listeners/HandleParsedDocument.php` + `HandleFailedParse.php` were created.
2. `php artisan migrate` and confirm the `parse_requests` table exists.
3. `php artisan parse:ping` and confirm the connected / valid-key / ready output. Try a wrong
   key to see the failure path.

## Testing the full roundtrip locally (mock backend)

The real SaaS parse endpoints are not live yet, but the package ships a local mock backend that
implements the wire contract (`POST /api/v1/parse`, status, markdown, ping). It does not really
parse; it returns a deterministic fake Markdown document after a short delay, so you can exercise
the complete submit to result roundtrip end to end.

In the consumer app, start the mock in one terminal:

```bash
php artisan parse:mock                 # listens on http://127.0.0.1:9321
php artisan parse:mock --delay=5       # stay "pending" longer, to watch polling
php artisan parse:mock --fail=pdf      # make .pdf submissions fail asynchronously
```

Point the SDK at it and use poll delivery, in `.env`:

```env
PARSE_BASE_URL=http://127.0.0.1:9321
PARSE_API_KEY=pfa_anything             # the mock only checks the pfa_ prefix
PARSE_DELIVERY=poll
```

The simplest proof needs no queue, because `->wait()` polls inline:

```bash
php artisan parse:file contracts/foo.pdf
# submits, waits, prints the mock Markdown
```

To exercise the event path, run a queue worker (`composer run dev` or `php artisan queue:work`
with a `database`/`redis` driver) and submit from tinker:

```php
Parse::file('contracts/foo.pdf')->parse();
// the poll job fires ParseCompleted; your HandleParsedDocument listener runs
```

Try the error paths too: an unsupported extension (e.g. `.rtf`) throws `ParseException`
synchronously, and `--fail=pdf` drives a `ParseFailed` event.

## Current status

| Area | State | Manually testable now |
|:--|:--|:--|
| `parse:install` (publish config, migration, listeners) | built | yes |
| `parse_requests` migration + `ParseRequest` model | built | yes |
| `parse:ping` | built, live | yes (needs the SaaS `GET /api/v1/ping`) |
| `Parse::file()->parse()` (managed multipart submit) | built | yes, against the mock backend |
| `->status()`, `->markdown()`, `->wait()`, poll job, events | built | yes, against the mock backend |
| Live parse against the real SaaS | pending | once the SaaS `POST /api/v1/parse` etc. ship |
| BYO presigning, signed webhook route | not implemented | later |

The parse flow is fully implemented and covered by Pest tests (against `Http::fake()` /
`Storage::fake()`, plus an integration test that drives the real code path against the mock
backend). It runs against the live SaaS once the corresponding endpoints are deployed.

## License

MIT
