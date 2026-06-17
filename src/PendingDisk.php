<?php

namespace ParseForArtisans;

use ParseForArtisans\Http\ApiClient;

/**
 * Intermediate returned by Parse::disk(); selects the disk, then the file(s).
 */
class PendingDisk
{
    public function __construct(
        protected ApiClient $client,
        protected string $disk,
    ) {}

    public function file(string $path): PendingParse
    {
        return (new PendingParse($this->client, $path))->disk($this->disk);
    }

    /**
     * @param  iterable<string>  $paths
     */
    public function files(iterable $paths): PendingBatch
    {
        return (new PendingBatch($this->client, $paths))->disk($this->disk);
    }
}
