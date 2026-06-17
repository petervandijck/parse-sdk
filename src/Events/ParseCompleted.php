<?php

namespace ParseForArtisans\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ParseForArtisans\Models\ParseRequest;

/**
 * Fired when a parse finishes successfully. The Markdown is already in storage;
 * read it with $event->request->markdown().
 */
class ParseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public ParseRequest $request) {}
}
