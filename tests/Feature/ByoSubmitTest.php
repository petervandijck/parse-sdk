<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Facades\Parse;
use ParseForArtisans\Models\ParseRequest;

beforeEach(function () {
    $disk = Storage::fake('s3');
    $disk->put('contracts/foo.pdf', '%PDF-1.4 fake bytes');
    $disk->buildTemporaryUrlsUsing(fn (string $path, $expiry) => "https://bucket.test/{$path}?get");
    $disk->buildTemporaryUploadUrlsUsing(fn (string $path, $expiry) => ['url' => "https://bucket.test/{$path}?put", 'headers' => []]);
});

it('presigns the customer disk and submits a BYO json payload', function () {
    Bus::fake();
    config()->set('parse.delivery', 'webhook');
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'server-id', 'status' => 'pending'], 202)]);

    $request = Parse::disk('s3')->file('contracts/foo.pdf')->parse();

    expect($request)->toBeInstanceOf(ParseRequest::class)
        ->and($request->disk)->toBe('s3')
        ->and($request->source_path)->toBe('contracts/foo.pdf')
        ->and($request->output_path)->toBe('parsed/contracts/foo.pdf.md');

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('Content-Type', 'application/json')
            && $request->url() === 'https://parse.test/api/v1/parse'
            && $body['extension'] === 'pdf'
            && $body['filename'] === 'contracts/foo.pdf'
            && $body['source']['mode'] === 'byo'
            && $body['source']['file_url'] === 'https://bucket.test/contracts/foo.pdf?get'
            && $body['source']['upload_url'] === 'https://bucket.test/parsed/contracts/foo.pdf.md?put'
            && $body['delivery']['mode'] === 'webhook'
            && $body['delivery']['callback_url'] === route('parse.webhook');
    });
});

it('honours ->to() for the BYO output object key', function () {
    Bus::fake();
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    $request = Parse::disk('s3')->file('contracts/foo.pdf')->to('out/foo.md')->parse();

    expect($request->output_path)->toBe('out/foo.md');

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['source']['upload_url']
        === 'https://bucket.test/out/foo.md?put');
});

it('reads BYO markdown from the customer disk without an API call', function () {
    Storage::disk('s3')->put('parsed/contracts/foo.pdf.md', '# Parsed locally');

    $request = new ParseRequest([
        'disk' => 's3',
        'source_path' => 'contracts/foo.pdf',
        'output_path' => 'parsed/contracts/foo.pdf.md',
        'status' => 'completed',
    ]);
    $request->save();

    Http::fake();

    expect($request->markdown())->toBe('# Parsed locally');

    Http::assertNothingSent();
});

it('throws when the BYO source file is missing', function () {
    Http::fake();

    Parse::disk('s3')->file('contracts/missing.pdf')->parse();
})->throws(ParseException::class);
