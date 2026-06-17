<?php

namespace ParseForArtisans\Commands;

use Illuminate\Console\Command;
use ParseForArtisans\Exceptions\ParseException;
use ParseForArtisans\Exceptions\ParseFailedException;
use ParseForArtisans\Exceptions\ParseTimeoutException;
use ParseForArtisans\Parse;

class FileCommand extends Command
{
    protected $signature = 'parse:file {path : A path on your disk or a public URL} {--save= : Write the result to this file instead of printing it}';

    protected $description = 'Parse a file and print (or save) the resulting Markdown.';

    public function handle(Parse $parse): int
    {
        $path = $this->argument('path');

        try {
            $request = str_starts_with($path, 'http')
                ? $parse->url($path)->parse()
                : $parse->file($path)->parse();
        } catch (ParseException $e) {
            $this->error("Submission failed ({$e->type}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Submitted {$path} (id: {$request->id}). Waiting for the result…");

        try {
            $markdown = $request->wait()->markdown();
        } catch (ParseFailedException $e) {
            $this->error("Parse failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (ParseTimeoutException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($save = $this->option('save')) {
            file_put_contents($save, $markdown);
            $this->info("Saved to {$save}");

            return self::SUCCESS;
        }

        $this->line($markdown);

        return self::SUCCESS;
    }
}
