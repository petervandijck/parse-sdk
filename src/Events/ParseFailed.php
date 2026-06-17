<?php

namespace ParseForArtisans\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ParseForArtisans\Models\ParseRequest;

/**
 * Fired when a parse finishes in a failed state. The typed reason is on
 * $event->request->error.
 */
class ParseFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public ParseRequest $request) {}
}
