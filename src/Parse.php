<?php

namespace ParseForArtisans;

use Illuminate\Support\Collection;
use ParseForArtisans\Http\ApiClient;
use ParseForArtisans\Models\ParseRequest;

/**
 * Entry point behind the Parse facade.
 */
class Parse
{
    public function __construct(protected ApiClient $client) {}

    /**
     * Begin a parse for a path on the default (or given) disk.
     */
    public function file(string $path): PendingParse
    {
        return new PendingParse($this->client, $path);
    }

    /**
     * Choose the disk first, then the file. (BYO disk is not implemented yet.)
     */
    public function disk(string $disk): PendingDisk
    {
        return new PendingDisk($this->client, $disk);
    }

    /**
     * Begin a batch parse for many paths. Returns a builder whose ->parse()
     * yields a collection of ParseRequest.
     *
     * @param  iterable<string>  $paths
     */
    public function files(iterable $paths): PendingBatch
    {
        return new PendingBatch($this->client, $paths);
    }

    /**
     * Parse a document hosted at a public URL. The SDK downloads the bytes and
     * submits them; the URL must carry a known file extension.
     */
    public function url(string $url): PendingParse
    {
        return new PendingParse($this->client, $url, isUrl: true);
    }

    /**
     * Look up a tracking row by id.
     */
    public function find(string $id): ?ParseRequest
    {
        return ParseRequest::find($id);
    }

    /**
     * Health/key check. Returns the ping body, e.g. ['ok' => true, 'plan' => 'free'].
     *
     * @return array{ok: bool, plan: string}
     */
    public function ping(): array
    {
        return $this->client->ping();
    }

    /**
     * @return Collection<int, ParseRequest>
     */
    public function all(): Collection
    {
        return ParseRequest::all();
    }
}
