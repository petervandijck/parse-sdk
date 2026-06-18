<?php

use ParseForArtisans\Delivery;

it('resolves auto to poll on local', function () {
    config()->set('parse.delivery', 'auto');
    $this->app['env'] = 'local';

    expect(Delivery::resolve())->toBe('poll');
});

it('resolves auto to webhook off local', function () {
    config()->set('parse.delivery', 'auto');
    $this->app['env'] = 'production';

    expect(Delivery::resolve())->toBe('webhook');
});

it('honours an explicit delivery mode regardless of environment', function () {
    config()->set('parse.delivery', 'webhook');
    $this->app['env'] = 'local';

    expect(Delivery::resolve())->toBe('webhook');
});
