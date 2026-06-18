<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Facades\Parse;
use ParseForArtisans\Jobs\PollParseStatus;
use ParseForArtisans\Models\ParseRequest;
use ParseForArtisans\Tests\Fixtures\TestDocument;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/foo.pdf', '%PDF-1.4 fake bytes');
});

it('submits a managed file and records a pending row', function () {
    Bus::fake();
    Http::fake([
        '*/api/v1/parse' => Http::response(['id' => 'server-id', 'status' => 'pending'], 202),
    ]);

    $request = Parse::file('contracts/foo.pdf')->parse();

    expect($request)->toBeInstanceOf(ParseRequest::class)
        ->and($request->status)->toBe('pending')
        ->and($request->source_path)->toBe('contracts/foo.pdf')
        ->and($request->output_path)->toBe('parsed/contracts/foo.pdf.md')
        ->and($request->disk)->toBeNull();

    expect(ParseRequest::find($request->id))->not->toBeNull();
});

it('maps builder options into the wire payload', function () {
    Bus::fake();
    config()->set('parse.delivery', 'poll');
    Http::fake([
        '*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202),
    ]);

    Parse::file('contracts/foo.pdf')
        ->ocr()
        ->ocrLanguage('spa')
        ->pages('1-20')
        ->frontmatter()
        ->parse();

    Http::assertSent(function ($request) {
        $part = collect($request->data())->firstWhere('name', 'payload');
        $payload = json_decode($part['contents'], true);

        return $request->url() === 'https://parse.test/api/v1/parse'
            && $payload['extension'] === 'pdf'
            && $payload['filename'] === 'contracts/foo.pdf'
            && $payload['delivery']['mode'] === 'poll'
            && $payload['options'] === [
                'force_ocr' => true,
                'pages' => '1-20',
                'frontmatter' => true,
                'ocr_language' => 'spa',
            ];
    });
});

it('dispatches the poll job under poll delivery', function () {
    Bus::fake();
    config()->set('parse.delivery', 'poll');
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    $request = Parse::file('contracts/foo.pdf')->parse();

    Bus::assertDispatched(PollParseStatus::class, fn ($job) => $job->requestId === $request->id);
});

it('does not dispatch the poll job under webhook delivery', function () {
    Bus::fake();
    config()->set('parse.delivery', 'webhook');
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    Parse::file('contracts/foo.pdf')->parse();

    Bus::assertNotDispatched(PollParseStatus::class);
});

it('associates the parsable model from ->for()', function () {
    Bus::fake();
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);
    $doc = TestDocument::create(['title' => 'Contract']);

    $request = Parse::file('contracts/foo.pdf')->for($doc)->parse();

    expect($request->parsable_type)->toBe(TestDocument::class)
        ->and($request->parsable_id)->toBe($doc->id)
        ->and($request->parsable->is($doc))->toBeTrue();
});

it('stores meta from ->withMeta()', function () {
    Bus::fake();
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    $request = Parse::file('contracts/foo.pdf')->withMeta(['invoice_id' => 42])->parse();

    expect($request->fresh()->meta)->toBe(['invoice_id' => 42]);
});

it('honours ->to() for the output path', function () {
    Bus::fake();
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    $request = Parse::file('contracts/foo.pdf')->to('out/foo.md')->parse();

    expect($request->output_path)->toBe('out/foo.md');
});

it('throws when the source file is missing', function () {
    Http::fake();

    Parse::file('contracts/missing.pdf')->parse();
})->throws(ParseException::class);

it('adds the signed-callback route to the payload under webhook delivery', function () {
    Bus::fake();
    config()->set('parse.delivery', 'webhook');
    Http::fake(['*/api/v1/parse' => Http::response(['id' => 'x', 'status' => 'pending'], 202)]);

    Parse::file('contracts/foo.pdf')->parse();

    Http::assertSent(function ($request) {
        $part = collect($request->data())->firstWhere('name', 'payload');
        $payload = json_decode($part['contents'], true);

        return $payload['delivery']['mode'] === 'webhook'
            && $payload['delivery']['callback_url'] === route('parse.webhook');
    });
});

dataset('submit_errors', [
    'invalid key' => [401, 'invalid_api_key'],
    'unsupported type' => [422, 'unsupported_type'],
    'unsupported option' => [422, 'unsupported_option'],
    'quota exceeded' => [402, 'quota_exceeded'],
    'invalid request' => [400, 'invalid_request'],
    'file too large' => [413, 'file_too_large'],
]);

it('throws a typed ParseException on each submit error', function (int $status, string $type) {
    Http::fake([
        '*/api/v1/parse' => Http::response(['error' => ['type' => $type, 'message' => 'nope']], $status),
    ]);

    try {
        Parse::file('contracts/foo.pdf')->parse();
        $this->fail('Expected ParseException');
    } catch (ParseException $e) {
        expect($e->type)->toBe($type)
            ->and($e->status)->toBe($status);
    }
})->with('submit_errors');
