<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ParseForArtisans\Events\ParseCompleted;
use ParseForArtisans\Events\ParseFailed;
use ParseForArtisans\Http\ApiClient;
use ParseForArtisans\Jobs\PollParseStatus;
use ParseForArtisans\Models\ParseRequest;

function makePendingRow(): ParseRequest
{
    return ParseRequest::create([
        'source_path' => 'contracts/foo.pdf',
        'output_path' => 'parsed/contracts/foo.pdf.md',
        'status' => 'pending',
    ]);
}

it('fires ParseCompleted and updates the row when the result lands', function () {
    Event::fake();
    $row = makePendingRow();
    Http::fake([
        "*/api/v1/parse/{$row->id}" => Http::response([
            'id' => $row->id,
            'status' => 'completed',
            'page_count' => 12,
            'credits_used' => 12,
            'duration_ms' => 8430,
        ]),
    ]);

    (new PollParseStatus($row->id))->handle(app(ApiClient::class));

    expect($row->fresh()->status)->toBe('completed')
        ->and($row->fresh()->page_count)->toBe(12);
    Event::assertDispatched(ParseCompleted::class, fn ($e) => $e->request->id === $row->id);
});

it('fires ParseFailed with the typed error', function () {
    Event::fake();
    $row = makePendingRow();
    Http::fake([
        "*/api/v1/parse/{$row->id}" => Http::response([
            'id' => $row->id,
            'status' => 'failed',
            'error' => ['type' => 'corrupt', 'message' => 'bad'],
        ]),
    ]);

    (new PollParseStatus($row->id))->handle(app(ApiClient::class));

    expect($row->fresh()->status)->toBe('failed')
        ->and($row->fresh()->error)->toBe('corrupt');
    Event::assertDispatched(ParseFailed::class);
});

it('releases itself while still pending', function () {
    Event::fake();
    $row = makePendingRow();
    Http::fake(["*/api/v1/parse/{$row->id}" => Http::response(['id' => $row->id, 'status' => 'pending'])]);

    $job = Mockery::mock(PollParseStatus::class.'[attempts,release]', [$row->id]);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once();

    $job->handle(app(ApiClient::class));

    Event::assertNotDispatched(ParseCompleted::class);
    Event::assertNotDispatched(ParseFailed::class);
});

it('gives up after max attempts and fires ParseFailed timeout', function () {
    Event::fake();
    config()->set('parse.poll.max_attempts', 3);
    $row = makePendingRow();
    Http::fake(["*/api/v1/parse/{$row->id}" => Http::response(['id' => $row->id, 'status' => 'pending'])]);

    $job = Mockery::mock(PollParseStatus::class.'[attempts,release]', [$row->id]);
    $job->shouldReceive('attempts')->andReturn(3);
    $job->shouldReceive('release')->never();

    $job->handle(app(ApiClient::class));

    expect($row->fresh()->status)->toBe('failed')
        ->and($row->fresh()->error)->toBe('timeout');
    Event::assertDispatched(ParseFailed::class);
});
