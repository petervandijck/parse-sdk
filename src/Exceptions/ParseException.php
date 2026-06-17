<?php

namespace ParseForArtisans\Exceptions;

use Exception;

/**
 * Thrown synchronously by Parse::file(...)->parse() when the SaaS rejects a
 * submission (bad key, unsupported type, unsupported option, quota, etc.).
 */
class ParseException extends Exception
{
    public function __construct(
        public readonly string $type,
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * Build from a non-2xx submit response body.
     *
     * @param  array{error?: array{type?: string, message?: string}}  $body
     */
    public static function fromResponse(array $body, int $status): self
    {
        $error = $body['error'] ?? [];

        return new self(
            type: $error['type'] ?? 'invalid_request',
            message: $error['message'] ?? 'The parse request was rejected.',
            status: $status,
        );
    }
}
