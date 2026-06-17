<?php

namespace ParseForArtisans\Commands;

use Illuminate\Console\Command;

/**
 * Launches the local mock SaaS backend (mock/server.php) so the full parse
 * roundtrip can be exercised before the real parse endpoints exist. Development
 * aid only; not for production use.
 */
class MockCommand extends Command
{
    protected $signature = 'parse:mock {--host=127.0.0.1} {--port=9321} {--delay=3 : Seconds a job stays pending before completing} {--fail= : Extension to fail asynchronously, e.g. pdf}';

    protected $description = 'Run a local mock of the Parse for Artisans API (development only).';

    public function handle(): int
    {
        $router = realpath(__DIR__.'/../../mock/server.php');

        if ($router === false) {
            $this->error('Could not locate mock/server.php.');

            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        $port = (string) $this->option('port');

        $this->info("Parse mock backend listening on http://{$host}:{$port}");
        $this->line("  Point the SDK at it: PARSE_BASE_URL=http://{$host}:{$port}");
        $this->line('  Press Ctrl+C to stop.');
        $this->newLine();

        $env = [
            'MOCK_DELAY' => (string) $this->option('delay'),
            'MOCK_FAIL' => (string) $this->option('fail'),
        ];

        $command = sprintf(
            '%s php -S %s:%s %s',
            $this->envPrefix($env),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($router),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, string>  $env
     */
    protected function envPrefix(array $env): string
    {
        return collect($env)
            ->reject(fn ($value) => $value === '')
            ->map(fn ($value, $key) => $key.'='.escapeshellarg($value))
            ->implode(' ');
    }
}
