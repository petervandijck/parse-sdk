<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ParseForArtisans\Events\ParseCompleted;
use ParseForArtisans\Events\ParseFailed;
use ParseForArtisans\Models\ParseRequest;

beforeEach(function () {
    config()->set('parse.webhook_secret', 'whsec_'.str_repeat('a', 40));
});

function newPendingRow(array $attributes = []): ParseRequest
{
    $row = new ParseRequest(array_merge([
        'source_path' => 'contracts/foo.pdf',
        'output_path' => 'parsed/contracts/foo.pdf.md',
        'status' => 'pending',
    ], $attributes));
    $row->save();

    return $row;
}

/**
 * POST a body to the webhook route, signed Stripe-style. Pass a $secret or $t
 * override to exercise tampering and replay.
 */
function postWebhook(array $body, ?string $secret = null, ?int $timestamp = null)
{
    $raw = json_encode($body);
    $secret ??= config('parse.webhook_secret');
    $timestamp ??= now()->timestamp;
    $v1 = hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);

    return test()->call(
        'POST',
        route('parse.webhook'),
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PARSE_SIGNATURE' => "t={$timestamp},v1={$v1}"],
        $raw,
    );
}

it('updates the row and fires ParseCompleted on a valid signature', function () {
    Event::fake([ParseCompleted::class, ParseFailed::class]);
    $row = newPendingRow();

    postWebhook([
        'id' => $row->id,
        'status' => 'completed',
        'page_count' => 5,
        'credits_used' => 5,
        'duration_ms' => 8430,
    ])->assertOk();

    $row->refresh();
    expect($row->status)->toBe('completed')
        ->and($row->page_count)->toBe(5)
        ->and($row->credits_used)->toBe(5);

    Event::assertDispatched(ParseCompleted::class, fn ($e) => $e->request->id === $row->id);
    Event::assertNotDispatched(ParseFailed::class);
});

it('fires ParseFailed with the typed error on a failed result', function () {
    Event::fake([ParseCompleted::class, ParseFailed::class]);
    $row = newPendingRow();

    postWebhook([
        'id' => $row->id,
        'status' => 'failed',
        'error' => ['type' => 'corrupt', 'message' => 'nope'],
    ])->assertOk();

    expect($row->fresh()->status)->toBe('failed')
        ->and($row->fresh()->error)->toBe('corrupt');

    Event::assertDispatched(ParseFailed::class);
});

it('rejects a tampered signature and leaves the row untouched', function () {
    Event::fake([ParseCompleted::class, ParseFailed::class]);
    $row = newPendingRow();

    postWebhook(['id' => $row->id, 'status' => 'completed'], secret: 'whsec_wrong')
        ->assertStatus(401);

    expect($row->fresh()->status)->toBe('pending');
    Event::assertNotDispatched(ParseCompleted::class);
});

it('rejects a signature outside the replay window', function () {
    Event::fake([ParseCompleted::class, ParseFailed::class]);
    $row = newPendingRow();

    postWebhook(['id' => $row->id, 'status' => 'completed'], timestamp: now()->timestamp - 600)
        ->assertStatus(401);

    expect($row->fresh()->status)->toBe('pending');
    Event::assertNotDispatched(ParseCompleted::class);
});

it('acks idempotently on an already-terminal row without re-firing', function () {
    Event::fake([ParseCompleted::class, ParseFailed::class]);
    $row = newPendingRow(['status' => 'completed', 'page_count' => 3]);

    postWebhook(['id' => $row->id, 'status' => 'completed', 'page_count' => 99])
        ->assertOk();

    expect($row->fresh()->page_count)->toBe(3); // unchanged
    Event::assertNotDispatched(ParseCompleted::class);
});

it('returns 404 for an unknown id', function () {
    postWebhook(['id' => (string) Str::uuid(), 'status' => 'completed'])
        ->assertStatus(404);
});
