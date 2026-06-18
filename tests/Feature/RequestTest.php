<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ParseForArtisans\Exceptions\ParseFailedException;
use ParseForArtisans\Exceptions\ParseTimeoutException;
use ParseForArtisans\Models\ParseRequest;

function newRow(array $attributes = []): ParseRequest
{
    return ParseRequest::create(array_merge([
        'source_path' => 'contracts/foo.pdf',
        'output_path' => 'parsed/contracts/foo.pdf.md',
        'status' => 'pending',
    ], $attributes));
}

it('reads markdown from the managed API', function () {
    $row = newRow();
    Http::fake([
        "*/api/v1/parse/{$row->id}/markdown" => Http::response('# Hello', 200, ['Content-Type' => 'text/markdown']),
    ]);

    expect($row->markdown())->toBe('# Hello');
});

it('reads markdown from the customer disk for a BYO row', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('parsed/contracts/foo.pdf.md', '# From the bucket');

    $row = newRow(['disk' => 's3', 'output_path' => 'parsed/contracts/foo.pdf.md']);

    Http::fake();

    expect($row->markdown())->toBe('# From the bucket');

    Http::assertNothingSent();
});

it('wait() returns the row on completion', function () {
    $row = newRow();
    Http::fake([
        "*/api/v1/parse/{$row->id}" => Http::response(['id' => $row->id, 'status' => 'completed', 'page_count' => 3]),
    ]);

    $result = $row->wait(timeout: 5, interval: 0);

    expect($result->status)->toBe('completed')
        ->and($result->page_count)->toBe(3);
});

it('wait() throws ParseFailedException on failure', function () {
    $row = newRow();
    Http::fake([
        "*/api/v1/parse/{$row->id}" => Http::response(['id' => $row->id, 'status' => 'failed', 'error' => ['type' => 'corrupt']]),
    ]);

    $row->wait(timeout: 5, interval: 0);
})->throws(ParseFailedException::class);

it('wait() throws ParseTimeoutException when it never finishes', function () {
    $row = newRow();
    Http::fake(["*/api/v1/parse/{$row->id}" => Http::response(['id' => $row->id, 'status' => 'pending'])]);

    $row->wait(timeout: 0, interval: 0);
})->throws(ParseTimeoutException::class);

it('status() reads the local column', function () {
    $row = newRow(['status' => 'completed']);

    expect($row->status())->toBe('completed');
});
