<?php

namespace ParseForArtisans\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ParseForArtisans\Events\ParseCompleted;
use ParseForArtisans\Events\ParseFailed;
use ParseForArtisans\Http\ApiClient;
use ParseForArtisans\Models\ParseRequest;

/**
 * Poll delivery (local dev). Re-checks the status endpoint, releasing itself
 * while pending, and fires the terminal event. A capped attempt count fires
 * ParseFailed so the event always eventually arrives.
 */
class PollParseStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Run unlimited times so the job's own attempt cap (parse.poll.max_attempts)
     * controls termination; a worker's --tries must not kill it before it fires
     * the terminal ParseFailed event.
     */
    public int $tries = 0;

    public function __construct(public string $requestId) {}

    public function handle(ApiClient $client): void
    {
        $request = ParseRequest::find($this->requestId);

        if ($request === null || in_array($request->status, ['completed', 'failed'], true)) {
            return;
        }

        $remote = $client->status($this->requestId);
        $request->applyStatus($remote);

        if ($request->status === 'completed') {
            ParseCompleted::dispatch($request);

            return;
        }

        if ($request->status === 'failed') {
            ParseFailed::dispatch($request);

            return;
        }

        if ($this->attempts() >= $this->maxAttempts()) {
            $request->applyStatus(['status' => 'failed', 'error' => ['type' => 'timeout']]);
            ParseFailed::dispatch($request);

            return;
        }

        $this->release($this->interval());
    }

    protected function interval(): int
    {
        return (int) config('parse.poll.interval', 5);
    }

    protected function maxAttempts(): int
    {
        return (int) config('parse.poll.max_attempts', 240);
    }
}
