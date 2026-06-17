<?php

namespace ParseForArtisans\Facades;

use Illuminate\Support\Facades\Facade;
use ParseForArtisans\PendingBatch;
use ParseForArtisans\PendingDisk;
use ParseForArtisans\PendingParse;

/**
 * @method static PendingParse file(string $path)
 * @method static PendingDisk disk(string $disk)
 * @method static PendingBatch files(iterable $paths)
 * @method static PendingParse url(string $url)
 * @method static \ParseForArtisans\Models\ParseRequest|null find(string $id)
 * @method static array{ok: bool, plan: string} ping()
 *
 * @see \ParseForArtisans\Parse
 */
class Parse extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ParseForArtisans\Parse::class;
    }
}
