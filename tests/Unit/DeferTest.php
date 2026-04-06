<?php

use Illuminate\Container\Container;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Webpatser\LaravelFiber\FiberDriver;

beforeEach(function () {
    $this->driver = new FiberDriver;

    // Bootstrap a minimal container with the app() helper
    // so defer() can register callbacks
    $container = new Container;
    $container->instance(DeferredCallbackCollection::class, new DeferredCallbackCollection);
    Container::setInstance($container);

    if (! function_exists('app')) {
        function app($abstract = null, array $parameters = [])
        {
            $container = Container::getInstance();

            if ($abstract === null) {
                return $container;
            }

            return $container->make($abstract, $parameters);
        }
    }
});

afterEach(fn () => Container::setInstance(null));

it('returns a DeferredCallback', function () {
    $result = $this->driver->defer([fn () => 'deferred']);

    expect($result)->toBeInstanceOf(DeferredCallback::class);
});

it('accepts a single closure', function () {
    $result = $this->driver->defer(fn () => 'single');

    expect($result)->toBeInstanceOf(DeferredCallback::class);
});

it('executes deferred tasks when invoked', function () {
    $executed = false;

    $callback = $this->driver->defer([
        function () use (&$executed) { $executed = true; },
    ]);

    $callback();

    expect($executed)->toBeTrue();
});
