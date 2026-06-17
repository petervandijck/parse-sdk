<?php

namespace ParseForArtisans\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use ParseForArtisans\Models\ParseRequest;

class TestDocument extends Model
{
    protected $table = 'test_documents';

    protected $guarded = [];

    public $timestamps = false;

    public function parse(): MorphOne
    {
        return $this->morphOne(ParseRequest::class, 'parsable');
    }
}
