<?php

use Illuminate\Support\Facades\Storage;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Facades\Parse;

/**
 * Drives the real SDK code path (no Http::fake) against the local mock backend,
 * proving the full submit -> poll -> markdown roundtrip end to end.
 */
function startMockServer(int $port, int $delay = 1): array
{
    $router = realpath(__DIR__.'/../../mock/server.php');

    $process = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['MOCK_DELAY' => (string) $delay],
    );

    // Wait for the server to accept connections.
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            $up = true;
            break;
        }
        usleep(100_000);
    }

    return [$process, $up];
}

beforeEach(function () {
    $this->port = random_int(9400, 9999);
    [$this->process, $up] = startMockServer($this->port, delay: 1);

    if (! $up) {
        proc_terminate($this->process);
        $this->markTestSkipped('Could not start the mock server.');
    }

    config()->set('parse.base_url', "http://127.0.0.1:{$this->port}");
    config()->set('parse.delivery', 'poll');

    Storage::fake('local');
    Storage::disk('local')->put('contracts/foo.pdf', '%PDF-1.4 fake bytes');
});

afterEach(function () {
    if (isset($this->process) && is_resource($this->process)) {
        proc_terminate($this->process);
        proc_close($this->process);
    }
});

it('completes a full submit, wait, and markdown roundtrip', function () {
    $request = Parse::file('contracts/foo.pdf')->ocr()->parse();

    expect($request->status)->toBe('pending');

    $request->wait(timeout: 15, interval: 1);

    expect($request->status)->toBe('completed')
        ->and($request->page_count)->toBe(3)
        ->and($request->credits_used)->toBe(3)
        ->and($request->markdown())->toContain('mock** backend');
});

it('throws a typed ParseException for an unsupported type', function () {
    Storage::disk('local')->put('notes.rtf', 'junk');

    Parse::file('notes.rtf')->parse();
})->throws(ParseException::class);
