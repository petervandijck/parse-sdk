<?php

namespace ParseForArtisans;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
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
        protected bool $isUrl = false,
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
     * a synchronous submission error. Managed mode (no disk) uploads the bytes;
     * BYO mode (a configured disk) presigns the customer disk and submits JSON.
     */
    public function parse(): ParseRequest
    {
        $disk = $this->disk ?? config('parse.disk');

        return $disk !== null
            ? $this->parseByo($disk)
            : $this->parseManaged();
    }

    /**
     * Managed mode: read the bytes from the default disk (or fetch the URL) and
     * upload them to the SaaS, which presigns its own bucket.
     */
    protected function parseManaged(): ParseRequest
    {
        [$contents, $filename, $extension] = $this->resolveSource();

        $id = (string) Str::uuid();
        $delivery = Delivery::resolve();

        $payload = [
            'id' => $id,
            'extension' => $extension,
            'filename' => $this->isUrl ? $filename : $this->path,
            'delivery' => $this->deliveryPayload($delivery),
            'options' => $this->options(),
        ];

        $result = $this->client->submitManaged($payload, $contents, $filename);

        return $this->persist($result, $id, null, $this->outputPath($filename), $delivery);
    }

    /**
     * BYO mode: presign GET + PUT against the customer disk and submit JSON so
     * the file bytes never transit the SaaS.
     */
    protected function parseByo(string $disk): ParseRequest
    {
        if ($this->isUrl) {
            throw new ParseException(
                type: 'invalid_request',
                message: 'A URL source cannot be parsed in BYO disk mode; unset parse.disk to use the managed bucket.',
            );
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($this->path)) {
            throw new ParseException(
                type: 'invalid_request',
                message: "File [{$this->path}] not found on disk [{$disk}].",
            );
        }

        $id = (string) Str::uuid();
        $delivery = Delivery::resolve();
        $outputPath = $this->outputPath($this->path);
        // The result is PUT back only after parsing finishes, so the TTL must
        // outlive the whole parse (large files can run for many minutes).
        $expiry = now()->addSeconds((int) config('parse.presign_ttl', 7200));

        $payload = [
            'id' => $id,
            'extension' => strtolower(pathinfo($this->path, PATHINFO_EXTENSION)),
            'filename' => $this->path,
            'source' => [
                'mode' => 'byo',
                'file_url' => $storage->temporaryUrl($this->path, $expiry),
                'upload_url' => $storage->temporaryUploadUrl($outputPath, $expiry)['url'],
            ],
            'delivery' => $this->deliveryPayload($delivery),
            'options' => $this->options(),
        ];

        $result = $this->client->submitByo($payload);

        return $this->persist($result, $id, $disk, $outputPath, $delivery);
    }

    /**
     * Persist the local correlation row and kick off poll delivery if needed.
     *
     * @param  array{id?: string, status?: string}  $result
     */
    protected function persist(array $result, string $id, ?string $disk, string $outputPath, string $delivery): ParseRequest
    {
        $request = new ParseRequest([
            'id' => $result['id'] ?? $id,
            'disk' => $disk,
            'source_path' => $this->path,
            'output_path' => $outputPath,
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

    /**
     * The delivery object sent to the SaaS. Under webhook delivery it carries
     * the SDK's signed-callback route so the SaaS knows where to POST.
     *
     * @return array<string, mixed>
     */
    protected function deliveryPayload(string $delivery): array
    {
        $payload = ['mode' => $delivery];

        if ($delivery === 'webhook') {
            $payload['callback_url'] = route('parse.webhook');
        }

        return $payload;
    }

    /**
     * Resolve the source bytes, filename, and extension from either a disk path
     * or a public URL (managed mode reads disk bytes from the default disk).
     *
     * @return array{0: string, 1: string, 2: string} [contents, filename, extension]
     */
    protected function resolveSource(): array
    {
        if ($this->isUrl) {
            try {
                $response = Http::get($this->path);
            } catch (\Throwable $e) {
                throw new ParseException(
                    type: 'invalid_request',
                    message: "Could not fetch URL [{$this->path}]: {$e->getMessage()}",
                );
            }

            if ($response->failed()) {
                throw new ParseException(
                    type: 'invalid_request',
                    message: "Could not fetch URL [{$this->path}] (HTTP {$response->status()}).",
                );
            }

            $urlPath = (string) parse_url($this->path, PHP_URL_PATH);
            $filename = basename($urlPath) ?: 'document';
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            if ($extension === '') {
                throw new ParseException(
                    type: 'invalid_request',
                    message: "Could not determine the file extension from URL [{$this->path}]. Include an extension in the URL.",
                );
            }

            return [$response->body(), $filename, $extension];
        }

        $sourceDisk = config('filesystems.default');

        if (! Storage::disk($sourceDisk)->exists($this->path)) {
            throw new ParseException(
                type: 'invalid_request',
                message: "File [{$this->path}] not found on disk [{$sourceDisk}].",
            );
        }

        return [
            Storage::disk($sourceDisk)->get($this->path),
            basename($this->path),
            strtolower(pathinfo($this->path, PATHINFO_EXTENSION)),
        ];
    }

    protected function outputPath(string $filename): string
    {
        if ($this->output !== null) {
            return $this->output;
        }

        $base = $this->isUrl ? $filename : $this->path;

        return rtrim((string) config('parse.output', 'parsed'), '/').'/'.$base.'.md';
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
