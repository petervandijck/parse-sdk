<?php

namespace ParseForArtisans\Http;

use Illuminate\Http\Request;

/**
 * Verifies the `X-Parse-Signature: t=<ts>,v1=<hmac>` header against the raw
 * request body, keyed by the account signing secret. The signed string is
 * "<t>.<raw_body>" and `t` must be within a 5-minute window to block replay.
 */
trait VerifiesSignature
{
    /**
     * The replay tolerance window, in seconds.
     */
    protected int $replayWindow = 300;

    protected function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('parse.webhook_secret');

        if ($secret === '') {
            return false;
        }

        ['t' => $timestamp, 'v1' => $signature] = $this->parseSignature(
            (string) $request->header('X-Parse-Signature', ''),
        );

        if ($timestamp === null || $signature === null) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > $this->replayWindow) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Split "t=<ts>,v1=<hmac>" into its parts.
     *
     * @return array{t: ?string, v1: ?string}
     */
    protected function parseSignature(string $header): array
    {
        $parts = ['t' => null, 'v1' => null];

        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);

            if (array_key_exists($key, $parts)) {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
