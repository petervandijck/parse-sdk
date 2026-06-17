<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use ParseForArtisans\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);
