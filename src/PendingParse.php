<?php

namespace ParseForArtisans;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Http\ApiClient;
use ParseForArtisans\Jobs\PollParseStatus;
use ParseForArtisans\Models\ParseRequest;

/**
 * Fluent builder for a single parse submission. Chain options, then ->parse().
 */
class PendingParse
{
    protected ?string $disk = null;

    protected ?string $output = null;

    protected ?Model $parsable = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    protected bool $forceOcr = false;

    protected ?string $pages = null;

    protected bool $frontmatter = false;

    protected ?string $ocrLanguage = null;

    public function __construct(
        protected ApiClient $client,
        protected string $path,
    ) {}

    public function disk(?string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function for(Model $model): self
    {
        $this->parsable = $model;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

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

    public function to(string $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Submit the file and return the tracking handle. Throws ParseException on
     * a synchronous submission error.
     */
    public function parse(): ParseRequest
    {
        $disk = $this->disk ?? config('parse.disk');

        if ($disk !== null) {
            throw new ParseException(
                type: 'not_implemented',
                message: 'BYO disk submit is not implemented yet; unset parse.disk to use the managed bucket.',
            );
        }

        $sourceDisk = config('filesystems.default');

        if (! Storage::disk($sourceDisk)->exists($this->path)) {
            throw new ParseException(
                type: 'invalid_request',
                message: "File [{$this->path}] not found on disk [{$sourceDisk}].",
            );
        }

        $id = (string) Str::uuid();
        $delivery = Delivery::resolve();

        $payload = [
            'id' => $id,
            'extension' => $this->extension(),
            'filename' => $this->path,
            'delivery' => ['mode' => $delivery],
            'options' => $this->options(),
        ];

        $result = $this->client->submitManaged(
            $payload,
            Storage::disk($sourceDisk)->get($this->path),
            basename($this->path),
        );

        $request = new ParseRequest([
            'id' => $result['id'] ?? $id,
            'disk' => null,
            'source_path' => $this->path,
            'output_path' => $this->outputPath(),
            'status' => $result['status'] ?? 'pending',
            'meta' => $this->meta ?: null,
        ]);

        if ($this->parsable !== null) {
            $request->parsable()->associate($this->parsable);
        }

        $request->save();

        if ($delivery === 'poll') {
            PollParseStatus::dispatch($request->id);
        }

        return $request;
    }

    protected function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    protected function outputPath(): string
    {
        if ($this->output !== null) {
            return $this->output;
        }

        return rtrim((string) config('parse.output', 'parsed'), '/').'/'.$this->path.'.md';
    }

    /**
     * The canonical wire options object.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [
            'force_ocr' => $this->forceOcr,
            'pages' => $this->pages,
            'frontmatter' => $this->frontmatter,
            'ocr_language' => $this->ocrLanguage,
        ];
    }
}
