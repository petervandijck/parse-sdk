<?php

namespace ParseForArtisans\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use ParseForArtisans\Exceptions\ParseException;

/**
 * Thin wrapper over the SaaS HTTP API. Every call carries the configured base
 * URL and API key. Submit failures become ParseException; status/markdown
 * surface the raw decoded body.
 */
class ApiClient
{
    public function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('parse.base_url'), '/'))
            ->withToken((string) config('parse.api_key'))
            ->acceptJson();
    }

    /**
     * GET /api/v1/ping
     *
     * @return array{ok: bool, plan: string}
     */
    public function ping(): array
    {
        $response = $this->request()->get('/api/v1/ping');

        if ($response->failed()) {
            throw ParseException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->json();
    }

    /**
     * POST /api/v1/parse (managed mode: multipart payload + file part).
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string}
     */
    public function submitManaged(array $payload, string $contents, string $filename): array
    {
        $response = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/api/v1/parse', ['payload' => json_encode($payload)]);

        if ($response->failed()) {
            throw ParseException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->json();
    }

    /**
     * POST /api/v1/parse (BYO mode: JSON body, no bytes transit the SaaS).
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string}
     */
    public function submitByo(array $payload): array
    {
        $response = $this->request()->asJson()->post('/api/v1/parse', $payload);

        if ($response->failed()) {
            throw ParseException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->json();
    }

    /**
     * GET /api/v1/parse/{id}
     *
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        $response = $this->request()->get("/api/v1/parse/{$id}");

        if ($response->failed()) {
            throw ParseException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->json();
    }

    /**
     * GET /api/v1/parse/{id}/markdown (managed read).
     */
    public function markdown(string $id): string
    {
        $response = $this->request()->get("/api/v1/parse/{$id}/markdown");

        if ($response->failed()) {
            throw ParseException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->body();
    }
}
