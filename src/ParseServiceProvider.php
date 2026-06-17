<?php

namespace ParseForArtisans;

use ParseForArtisans\Commands\FileCommand;
use ParseForArtisans\Commands\MockCommand;
use ParseForArtisans\Commands\PingCommand;
use ParseForArtisans\Http\ApiClient;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ParseServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('parse')
            ->hasConfigFile('parse')
            ->hasMigration('create_parse_requests_table')
            ->hasCommands([PingCommand::class, FileCommand::class, MockCommand::class])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->publish('listeners')
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ApiClient::class);

        $this->app->singleton(Parse::class, fn ($app) => new Parse($app->make(ApiClient::class)));
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/stubs/HandleParsedDocument.php.stub' => app_path('Listeners/HandleParsedDocument.php'),
                __DIR__.'/../resources/stubs/HandleFailedParse.php.stub' => app_path('Listeners/HandleFailedParse.php'),
            ], 'parse-listeners');
        }
    }
}
