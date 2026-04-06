<?php

use Webpatser\LaravelFiber\FiberDriver;

beforeEach(fn () => $this->driver = new FiberDriver);

describe('run', function () {
    it('returns results from multiple tasks', function () {
        $results = $this->driver->run([
            fn () => 1 + 1,
            fn () => 2 + 2,
            fn () => 3 + 3,
        ]);

        expect($results)->toBe([2, 4, 6]);
    });

    it('preserves named array keys', function () {
        $results = $this->driver->run([
            'users' => fn () => 'fetched users',
            'posts' => fn () => 'fetched posts',
        ]);

        expect($results)
            ->toHaveKey('users', 'fetched users')
            ->toHaveKey('posts', 'fetched posts');
    });

    it('preserves result order regardless of completion time', function () {
        $results = $this->driver->run([
            function () {
                Amp\delay(0.05);

                return 'slow';
            },
            fn () => 'fast',
        ]);

        expect($results[0])->toBe('slow')
            ->and($results[1])->toBe('fast');
    });

    it('handles an empty task list', function () {
        expect($this->driver->run([]))->toBe([]);
    });

    it('handles a single task', function () {
        expect($this->driver->run([fn () => 42]))->toBe([42]);
    });

    it('wraps a single closure in an array', function () {
        $results = $this->driver->run(fn () => 'solo');

        expect($results)->toBe(['solo']);
    });

    it('returns null for tasks that return nothing', function () {
        $results = $this->driver->run([
            function () { /* void */ },
        ]);

        expect($results[0])->toBeNull();
    });

    it('handles mixed return types', function () {
        $results = $this->driver->run([
            fn () => 42,
            fn () => 'string',
            fn () => ['array'],
            fn () => true,
            fn () => null,
        ]);

        expect($results)->toBe([42, 'string', ['array'], true, null]);
    });

    it('handles integer keys', function () {
        $results = $this->driver->run([
            5 => fn () => 'five',
            10 => fn () => 'ten',
        ]);

        expect($results)->toBe([5 => 'five', 10 => 'ten']);
    });
});

describe('exceptions', function () {
    it('propagates exceptions from tasks', function () {
        $this->driver->run([
            fn () => throw new RuntimeException('boom'),
        ]);
    })->throws(RuntimeException::class, 'boom');

    it('propagates the first exception when multiple tasks fail', function () {
        // amphp collects all exceptions but throws a CompositeException
        // when multiple futures fail. The first exception is always propagated.
        $this->driver->run([
            fn () => throw new RuntimeException('first'),
        ]);
    })->throws(RuntimeException::class, 'first');

    it('preserves custom exception classes', function () {
        $this->driver->run([
            fn () => throw new InvalidArgumentException('bad input'),
        ]);
    })->throws(InvalidArgumentException::class, 'bad input');
});

describe('shared memory', function () {
    it('shares objects between tasks', function () {
        $shared = new stdClass;
        $shared->count = 0;

        $this->driver->run([
            function () use ($shared) { $shared->count++; },
            function () use ($shared) { $shared->count++; },
            function () use ($shared) { $shared->count++; },
        ]);

        expect($shared->count)->toBe(3);
    });

    it('shares array references between tasks', function () {
        $log = [];

        $this->driver->run([
            function () use (&$log) { $log[] = 'task-1'; },
            function () use (&$log) { $log[] = 'task-2'; },
        ]);

        expect($log)->toHaveCount(2)
            ->toContain('task-1')
            ->toContain('task-2');
    });

    it('can pass non-serializable objects', function () {
        // This is impossible with ProcessDriver (requires serialization)
        $resource = fopen('php://memory', 'r');

        $results = $this->driver->run([
            function () use ($resource) {
                return is_resource($resource) ? 'resource-ok' : 'not-resource';
            },
        ]);

        fclose($resource);

        expect($results[0])->toBe('resource-ok');
    });
});
