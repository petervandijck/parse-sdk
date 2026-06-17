<?php

namespace ParseForArtisans;

use Illuminate\Support\Collection;
use ParseForArtisans\Http\ApiClient;
use ParseForArtisans\Models\ParseRequest;

/**
 * Builder for a batch of files. Options apply to every file. ->parse() submits
 * each one and returns a collection of ParseRequest.
 *
 * Note: each file is submitted on its own in this release; a single batch wire
 * call is a later optimization.
 */
class PendingBatch
{
    /** @var array<int, string> */
    protected array $paths;

    protected ?string $disk = null;

    protected bool $forceOcr = false;

    protected ?string $pages = null;

    protected bool $frontmatter = false;

    protected ?string $ocrLanguage = null;

    /**
     * @param  iterable<string>  $paths
     */
    public function __construct(
        protected ApiClient $client,
        iterable $paths,
    ) {
        $this->paths = collect($paths)->values()->all();
    }

    public function disk(?string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function ocr(bool $force = true): self
    {
        $this->forceOcr = $force;

        return $this;
    }

    public function ocrLanguage(string $language): self
    {
        $this->ocrLanguage = $language;

        return $this;
    }

    public function pages(string $pages): self
    {
        $this->pages = $pages;

        return $this;
    }

    public function frontmatter(bool $frontmatter = true): self
    {
        $this->frontmatter = $frontmatter;

        return $this;
    }

    /**
     * @return Collection<int, ParseRequest>
     */
    public function parse(): Collection
    {
        return collect($this->paths)->map(function (string $path): ParseRequest {
            $pending = (new PendingParse($this->client, $path))
                ->disk($this->disk)
                ->ocr($this->forceOcr)
                ->frontmatter($this->frontmatter);

            if ($this->pages !== null) {
                $pending->pages($this->pages);
            }

            if ($this->ocrLanguage !== null) {
                $pending->ocrLanguage($this->ocrLanguage);
            }

            return $pending->parse();
        });
    }
}
