<?php

namespace ParseForArtisans;

/**
 * Resolves the configured delivery mode. "auto" polls on local and uses
 * webhooks everywhere else.
 */
class Delivery
{
    public static function resolve(): string
    {
        $configured = config('parse.delivery', 'auto');

        if ($configured !== 'auto') {
            return $configured;
        }

        return app()->environment('local') ? 'poll' : 'webhook';
    }
}
