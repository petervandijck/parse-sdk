<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Your API key from the parseforartisans.com dashboard, and the webhook
    | signing secret used to verify result callbacks in production.
    |
    */

    'base_url' => env('PARSE_BASE_URL', 'https://parseforartisans.com'),

    'api_key' => env('PARSE_API_KEY'),

    'webhook_secret' => env('PARSE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk your source files live on. Leave unset to use our
    | managed dev bucket (zero config). Point it at your own bucket for
    | production so your file bytes never transit us.
    |
    */

    'disk' => env('PARSE_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Output prefix
    |--------------------------------------------------------------------------
    |
    | Where parsed Markdown is written, mirroring the source path beneath it.
    | "contracts/foo.pdf" becomes "parsed/contracts/foo.pdf.md".
    |
    */

    'output' => 'parsed',

    /*
    |--------------------------------------------------------------------------
    | BYO presign lifetime
    |--------------------------------------------------------------------------
    |
    | How long, in seconds, the presigned GET/PUT URLs handed to the parser stay
    | valid in BYO mode. This must outlive the whole parse: the result is PUT
    | back only after parsing finishes, so a large document (up to ~1 GB) that
    | runs for a while needs a TTL longer than its parse time. Defaults to two
    | hours to match the backend's worker budget.
    |
    */

    'presign_ttl' => (int) env('PARSE_PRESIGN_TTL', 7200),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | How finished results reach your app. "auto" polls on local and uses
    | webhooks everywhere else. See the Local Development docs.
    |
    */

    'delivery' => env('PARSE_DELIVERY', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Poll job tuning
    |--------------------------------------------------------------------------
    |
    | When delivery is "poll", the background job re-checks the API on this
    | interval (seconds) and gives up after this many attempts, firing a
    | ParseFailed event so the result always eventually arrives.
    |
    */

    'poll' => [
        'interval' => 5,
        'max_attempts' => 240,
    ],

];
