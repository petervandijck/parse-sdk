<?php

namespace ParseForArtisans\Commands;

use Illuminate\Console\Command;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Http\ApiClient;

class PingCommand extends Command
{
    protected $signature = 'parse:ping';

    protected $description = 'Check that your API key works and the service is reachable.';

    public function handle(ApiClient $client): int
    {
        $host = parse_url((string) config('parse.base_url'), PHP_URL_HOST) ?: config('parse.base_url');

        try {
            $body = $client->ping();
        } catch (ParseException $e) {
            $this->line("<fg=red>✘</> Could not connect to {$host}");
            $this->line("  {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("<fg=green>✔</> Connected to {$host}");
        $this->line('<fg=green>✔</> API key valid (plan: '.($body['plan'] ?? 'unknown').')');
        $this->line('<fg=green>✔</> Ready to parse');

        return self::SUCCESS;
    }
}
