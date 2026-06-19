# Contributing

Notes for working on the SDK itself. End-user documentation lives at
[parseforartisans.com/docs](https://parseforartisans.com/docs).

## Running the test suite

```bash
composer lint        # pint --parallel
composer test        # pint --test, then pest
```

The suite uses [Orchestra Testbench](https://github.com/orchestral/testbench) to boot a minimal
Laravel app and [Pest](https://pestphp.com) for the tests. Run `vendor/bin/pint` before
finalizing any PHP change.

## Manual test against the live SaaS

To test local, unreleased changes from a separate Laravel app, require the SDK from a local path
instead of Packagist. In the consumer app's `composer.json`:

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
4. `php artisan parse:file <a public PDF URL>` (or a path on your default disk): submits, waits,
   and prints the parsed Markdown. Add `--save=out.md` to write it to a file.
5. For the event path, run `composer run dev` (or `queue:listen` on a `database`/`redis` driver)
   and call `Parse::file('contracts/foo.pdf')->parse()` from tinker; your `HandleParsedDocument`
   listener fires with the result.

## Offline testing (bundled mock backend)

The live SaaS is the primary end-to-end target (above). For offline work the package ships a
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
