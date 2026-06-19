# Parse for Artisans (Laravel SDK)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/parseforartisans/laravel.svg)](https://packagist.org/packages/parseforartisans/laravel)
[![License](https://img.shields.io/packagist/l/parseforartisans/laravel.svg)](https://packagist.org/packages/parseforartisans/laravel)

Parse documents to Markdown from Laravel. Install the package, set an API key, and call
`Parse::file('contract.pdf')->parse()`; the result arrives in a `ParseCompleted` event. This is
the client SDK for [parseforartisans.com](https://parseforartisans.com).

## Installation

```bash
composer require parseforartisans/laravel
```

Then set your API key and run `php artisan parse:install`. The
[installation guide](https://parseforartisans.com/docs/installation) covers the full setup.

## Usage

```php
use ParseForArtisans\Facades\Parse;

$parse = Parse::file('contracts/foo.pdf')->parse();   // returns a handle immediately
```

The result arrives asynchronously in a `ParseCompleted` event:

```php
public function handle(ParseCompleted $event): void
{
    $markdown = $event->request->markdown();
}
```

Options, configuration, delivery modes, and storage modes are all covered in the docs:
[parseforartisans.com/docs](https://parseforartisans.com/docs).

## Requirements

- PHP `^8.3`
- Laravel 12 or 13

## License

MIT
