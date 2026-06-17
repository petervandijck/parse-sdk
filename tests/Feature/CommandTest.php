<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('parse:ping reports a reachable service and valid key', function () {
    Http::fake(['*/api/v1/ping' => Http::response(['ok' => true, 'plan' => 'free'])]);

    $this->artisan('parse:ping')
        ->assertSuccessful()
        ->expectsOutputToContain('Connected to parse.test')
        ->expectsOutputToContain('plan: free')
        ->expectsOutputToContain('Ready to parse');
});

it('parse:ping fails on a bad key', function () {
    Http::fake(['*/api/v1/ping' => Http::response(['error' => ['type' => 'invalid_api_key', 'message' => 'Missing or invalid API key.']], 401)]);

    $this->artisan('parse:ping')->assertFailed();
});

it('parse:file submits, waits, and prints the markdown', function () {
    Bus::fake();
    Storage::fake('local');
    Storage::disk('local')->put('contracts/foo.pdf', 'bytes');

    Http::fake([
        '*/api/v1/parse' => Http::response(['id' => 'job-1', 'status' => 'pending'], 202),
        '*/api/v1/parse/job-1/markdown' => Http::response('# Parsed', 200),
        '*/api/v1/parse/job-1' => Http::response(['id' => 'job-1', 'status' => 'completed']),
    ]);

    $this->artisan('parse:file', ['path' => 'contracts/foo.pdf'])
        ->assertSuccessful()
        ->expectsOutputToContain('# Parsed');
});
