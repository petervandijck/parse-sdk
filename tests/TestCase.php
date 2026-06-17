<?php

namespace ParseForArtisans\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ParseForArtisans\ParseServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ParseServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('parse.base_url', 'https://parse.test');
        $app['config']->set('parse.api_key', 'pfa_testkey0000000000000000000000000000000000');
        $app['config']->set('parse.disk', null);
        $app['config']->set('parse.delivery', 'auto');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
