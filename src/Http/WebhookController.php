<?php

namespace ParseForArtisans\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ParseForArtisans\Events\ParseCompleted;
use ParseForArtisans\Events\ParseFailed;
use ParseForArtisans\Models\ParseRequest;

/**
 * Receives the SaaS result webhook (production delivery). Verifies the
 * signature, enforces the replay window, updates the local row idempotently,
 * and fires the terminal event. Mirrors the poll job's terminal handling.
 */
class WebhookController
{
    use VerifiesSignature;

    public function __invoke(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response('Invalid signature.', 401);
        }

        $payload = $request->json()->all();
        $id = $payload['id'] ?? null;

        if (! is_string($id)) {
            return response('Missing id.', 400);
        }

        $parseRequest = ParseRequest::find($id);

        if ($parseRequest === null) {
            return response('Unknown request.', 404);
        }

        // Already terminal: ack and stop. The webhook is delivered at-least-once.
        if (in_array($parseRequest->status, ['completed', 'failed'], true)) {
            return response('', 200);
        }

        $parseRequest->applyStatus($payload);

        if ($parseRequest->status === 'completed') {
            ParseCompleted::dispatch($parseRequest);
        } elseif ($parseRequest->status === 'failed') {
            ParseFailed::dispatch($parseRequest);
        }

        return response('', 200);
    }
}
