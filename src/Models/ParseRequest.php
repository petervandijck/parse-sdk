<?php

namespace ParseForArtisans\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Exceptions\ParseFailedException;
use ParseForArtisans\Exceptions\ParseTimeoutException;
use ParseForArtisans\Http\ApiClient;

/**
 * Local correlation row for one parse submission. SDK-owned; the SaaS only
 * ever echoes back the id.
 *
 * @property string $id
 * @property ?string $disk
 * @property string $source_path
 * @property string $output_path
 * @property string $status
 * @property ?int $page_count
 * @property ?int $credits_used
 * @property ?string $error
 * @property ?array<string, mixed> $meta
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property ?int $duration_ms
 */
class ParseRequest extends Model
{
    use HasUuids;

    protected $table = 'parse_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'page_count' => 'integer',
        'credits_used' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The customer model associated via ->for($model). Null when none was set.
     */
    public function parsable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Current status from the local row: pending, completed, or failed.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * Fetch the parsed Markdown. Managed mode reads it from the SaaS API; BYO
     * (a configured disk) is not implemented in this release.
     */
    public function markdown(): string
    {
        if ($this->disk !== null) {
            throw new ParseException(
                type: 'not_implemented',
                message: 'BYO disk reads are not implemented yet; use the managed bucket (unset parse.disk).',
            );
        }

        return app(ApiClient::class)->markdown($this->id);
    }

    /**
     * Synchronously poll until the parse reaches a terminal state. For CLI and
     * tinker only; never call this in a web request. Updates the local row and
     * returns it on success.
     *
     * @throws ParseFailedException when the parse fails
     * @throws ParseTimeoutException when it does not finish in time
     */
    public function wait(int $timeout = 120, int $interval = 2): self
    {
        $client = app(ApiClient::class);
        $deadline = $this->now() + $timeout;

        do {
            $remote = $client->status($this->id);
            $this->applyStatus($remote);

            if ($this->status === 'completed') {
                return $this;
            }

            if ($this->status === 'failed') {
                throw new ParseFailedException($this->error ?? 'The parse failed.');
            }

            if ($this->now() >= $deadline) {
                break;
            }

            $this->sleep($interval);
        } while (true);

        throw new ParseTimeoutException("Parse {$this->id} did not complete within {$timeout}s.");
    }

    /**
     * Merge a status-endpoint / webhook result body onto the row and persist.
     *
     * @param  array<string, mixed>  $remote
     */
    public function applyStatus(array $remote): self
    {
        $this->fill([
            'status' => $remote['status'] ?? $this->status,
            'page_count' => $remote['page_count'] ?? $this->page_count,
            'credits_used' => $remote['credits_used'] ?? $this->credits_used,
            'started_at' => $remote['started_at'] ?? $this->started_at,
            'completed_at' => $remote['completed_at'] ?? $this->completed_at,
            'duration_ms' => $remote['duration_ms'] ?? $this->duration_ms,
            'error' => isset($remote['error']) && is_array($remote['error'])
                ? ($remote['error']['type'] ?? null)
                : $this->error,
        ]);

        $this->save();

        return $this;
    }

    protected function now(): int
    {
        return time();
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
