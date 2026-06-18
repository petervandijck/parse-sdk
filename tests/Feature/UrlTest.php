<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Facades\Parse;
use ParseForArtisans\Models\ParseRequest;

it('downloads a URL and submits it as a managed parse', function () {
    Bus::fake();
    Http::fake([
        'https://example.com/docs/invoice.pdf' => Http::response('%PDF-1.4 url bytes', 200),
        '*/api/v1/parse' => Http::response(['id' => 'server-id', 'status' => 'pending'], 202),
    ]);

    $request = Parse::url('https://example.com/docs/invoice.pdf')->parse();

    expect($request)->toBeInstanceOf(ParseRequest::class)
        ->and($request->status)->toBe('pending')
        ->and($request->source_path)->toBe('https://example.com/docs/invoice.pdf')
        ->and($request->output_path)->toBe('parsed/invoice.pdf.md');

    // The bytes were fetched from the URL.
    Http::assertSent(fn ($r) => $r->url() === 'https://example.com/docs/invoice.pdf' && $r->method() === 'GET');

    // And submitted with the extension/filename derived from the URL.
    Http::assertSent(function ($r) {
        if ($r->url() !== 'https://parse.test/api/v1/parse') {
            return false;
        }

        $part = collect($r->data())->firstWhere('name', 'payload');
        $payload = json_decode($part['contents'], true);

        return $payload['extension'] === 'pdf' && $payload['filename'] === 'invoice.pdf';
    });
});

it('throws when the URL has no file extension', function () {
    Http::fake(['*' => Http::response('bytes', 200)]);

    Parse::url('https://example.com/download')->parse();
})->throws(ParseException::class);

it('throws when the URL download fails', function () {
    Http::fake(['https://example.com/x.pdf' => Http::response('not found', 404)]);

    Parse::url('https://example.com/x.pdf')->parse();
})->throws(ParseException::class);
