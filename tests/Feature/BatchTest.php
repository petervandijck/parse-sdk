<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ParseForArtisans\Facades\Parse;
use ParseForArtisans\Models\ParseRequest;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('a.pdf', '%PDF-1.4 a');
    Storage::disk('local')->put('b.pdf', '%PDF-1.4 b');
});

it('submits one request per file and returns a collection', function () {
    Bus::fake();
    Http::fake([
        '*/api/v1/parse' => Http::sequence()
            ->push(['id' => 'id-a', 'status' => 'pending'], 202)
            ->push(['id' => 'id-b', 'status' => 'pending'], 202),
    ]);

    $batch = Parse::files(['a.pdf', 'b.pdf'])->frontmatter()->parse();

    expect($batch)->toHaveCount(2)
        ->and($batch->every(fn ($r) => $r instanceof ParseRequest))->toBeTrue();

    Http::assertSentCount(2);
    expect(ParseRequest::count())->toBe(2);
});
