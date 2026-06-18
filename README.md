# Parse for Artisans (Laravel SDK)

Parse documents to Markdown from Laravel. Install the package, set an API key, and call
`Parse::file('contract.pdf')->parse()`; the result arrives in a `ParseCompleted` event. This is
the client SDK for [parseforartisans.com](https://parseforartisans.com).

> **Pre-release.** Under active development, not yet on Packagist. The full **managed** parse
> round trip works against the live API at parseforartisans.com today: `parse:ping`,
> `parse:file`, and `Parse::file()->parse()` to a `ParseCompleted` event (poll delivery locally).
> BYO-bucket presigning and signed webhook delivery are not implemented yet. See
> [Current status](#current-status).

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

Point the SDK at the live SaaS and set a real key in the consumer app's `.env`:

```env
PARSE_BASE_URL=https://parseforartisans.com  # the live SaaS (the default; override for staging/local)
PARSE_API_KEY=pfa_...                        # a key minted in the dashboard
```

Then run, in order:

1. `php artisan parse:install` and confirm `config/parse.php`, the migration, and
   `app/Listeners/HandleParsedDocument.php` + `HandleFailedParse.php` were created.
2. `php artisan migrate` and confirm the `parse_requests` table exists.
3. `php artisan parse:ping` and confirm the connected / valid-key / ready output. Try a wrong
   key to see the failure path.
4. `php artisan parse:file <a public PDF URL>` (or a path on your default disk) — submits, waits,
   and prints the parsed Markdown. Add `--save=out.md` to write it to a file.
5. For the event path, run `composer run dev` (or `queue:listen` on a `database`/`redis` driver)
   and call `Parse::file('contracts/foo.pdf')->parse()` from tinker; your `HandleParsedDocument`
   listener fires with the result.

## Offline testing (bundled mock backend)

The live SaaS is the primary end-to-end target (above). For offline work the package also ships a
local mock backend that implements the wire contract (`POST /api/v1/parse`, status, markdown,
ping) and returns deterministic fake Markdown after a short delay. It is a dev aid, not an
installed Artisan command, so run it with PHP's built-in server:

```bash
php -S 127.0.0.1:9321 vendor/parseforartisans/laravel/mock/server.php
MOCK_DELAY=5 php -S 127.0.0.1:9321 vendor/parseforartisans/laravel/mock/server.php   # stay pending longer
MOCK_FAIL=pdf php -S 127.0.0.1:9321 vendor/parseforartisans/laravel/mock/server.php  # fail .pdf async
```

Point the SDK at it with poll delivery, in `.env`:

```env
PARSE_BASE_URL=http://127.0.0.1:9321
PARSE_API_KEY=pfa_anything             # the mock only checks the pfa_ prefix
PARSE_DELIVERY=poll
```

Then `php artisan parse:file contracts/foo.pdf` (needs no queue; `->wait()` polls inline), or run
a worker and `Parse::file('contracts/foo.pdf')->parse()` from tinker to drive the
`ParseCompleted` event. An unsupported extension (e.g. `.rtf`) throws `ParseException`
synchronously, and `MOCK_FAIL=pdf` drives a `ParseFailed` event.

## Current status

| Area | State | Manually testable now |
|:--|:--|:--|
| `parse:install` (publish config, migration, listeners) | built | yes |
| `parse_requests` migration + `ParseRequest` model | built | yes |
| `parse:ping` | built, live | yes |
| `Parse::file()->parse()` (managed multipart submit) | built, live | yes |
| `Parse::url()` (download + submit) | built, live | yes |
| `->status()`, `->markdown()`, `->wait()`, poll job, events | built, live | yes |
| Live managed parse against the real SaaS | done, verified | yes |
| BYO presigning, signed webhook delivery | not implemented | M5 |

The managed parse flow is implemented, covered by Pest tests (`Http::fake()` / `Storage::fake()`
plus a mock-backend integration test), and verified end to end against the live SaaS. BYO storage
and signed webhook delivery land in a later release.

## License

MIT
