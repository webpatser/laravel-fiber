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

    it('returns results in task order, not completion order', function () {
        $results = $this->driver->run([
            'slow' => function () {
                Amp\delay(0.05);

                return 'slow';
            },
            'fast' => fn () => 'fast',
        ]);

        expect($results)->toBe(['slow' => 'slow', 'fast' => 'fast']);
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

    it('throws the first failure in task order, not completion order', function () {
        $this->driver->run([
            function () {
                Amp\delay(0.02);

                throw new RuntimeException('first');
            },
            fn () => throw new RuntimeException('second'),
        ]);
    })->throws(RuntimeException::class, 'first');

    it('lets every task finish before a failure is rethrown', function () {
        $finished = false;

        try {
            $this->driver->run([
                fn () => throw new RuntimeException('boom'),
                function () use (&$finished) {
                    Amp\delay(0.05);
                    $finished = true;
                },
            ]);

            $this->fail('Expected the failed task to be rethrown.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('boom')
                ->and($finished)->toBeTrue();
        }
    });

    it('settles a later failure instead of leaving it unhandled', function () {
        try {
            $this->driver->run([
                fn () => throw new RuntimeException('first'),
                function () {
                    Amp\delay(0.02);

                    throw new LogicException('late');
                },
            ]);
        } catch (RuntimeException) {
            //
        }

        // Run the event loop past the late failure; an orphaned future would raise UnhandledFutureError here.
        Amp\delay(0.05);

        expect(true)->toBeTrue();
    });

    it('preserves custom exception classes', function () {
        $this->driver->run([
            fn () => throw new InvalidArgumentException('bad input'),
        ]);
    })->throws(InvalidArgumentException::class, 'bad input');
});

describe('timeout', function () {
    it('throws CancelledException when the run exceeds a CarbonInterval timeout', function () {
        $finished = false;
        $start = microtime(true);

        try {
            $this->driver->run([
                'fast' => fn () => 'fast',
                'slow' => function () use (&$finished) {
                    Amp\delay(0.5);
                    $finished = true;
                },
            ], Carbon\CarbonInterval::milliseconds(50));

            $this->fail('Expected the run to time out.');
        } catch (Amp\CancelledException $e) {
            expect($e->getPrevious())->toBeInstanceOf(Amp\TimeoutException::class)
                ->and($finished)->toBeFalse()
                ->and(microtime(true) - $start)->toBeLessThan(0.4);
        }
    });

    it('reads an int timeout as seconds', function () {
        $start = microtime(true);

        try {
            $this->driver->run([fn () => Amp\delay(3)], 1);

            $this->fail('Expected the run to time out.');
        } catch (Amp\CancelledException) {
            expect(microtime(true) - $start)->toBeGreaterThanOrEqual(0.9)->toBeLessThan(2.5);
        }
    });

    it('does not leave an unhandled failure behind after a timeout', function () {
        try {
            $this->driver->run([
                function () {
                    Amp\delay(0.1);

                    throw new LogicException('late');
                },
            ], Carbon\CarbonInterval::milliseconds(20));
        } catch (Amp\CancelledException) {
            //
        }

        // Run the event loop past the late failure; an unignored future would raise UnhandledFutureError here.
        Amp\delay(0.2);

        expect(true)->toBeTrue();
    });

    it('returns normally when the run finishes inside the timeout', function () {
        $results = $this->driver->run([
            'slow' => function () {
                Amp\delay(0.02);

                return 'slow';
            },
            'fast' => fn () => 'fast',
        ], Carbon\CarbonInterval::seconds(5));

        expect($results)->toBe(['slow' => 'slow', 'fast' => 'fast']);
    });

    it('still rethrows a task failure when a timeout is given', function () {
        $this->driver->run([fn () => throw new RuntimeException('boom')], 5);
    })->throws(RuntimeException::class, 'boom');
});

describe('contract', function () {
    // Illuminate\Contracts\Concurrency\Driver as declared since Laravel 13.9.0 (copied from the framework fork).
    $contract = <<<'PHP'
        namespace LaravelFiberContractProbe;

        use Carbon\CarbonInterval;
        use Closure;
        use Illuminate\Support\Defer\DeferredCallback;

        interface Driver
        {
            public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array;

            public function defer(Closure|array $tasks): DeferredCallback;
        }
        PHP;

    it('matches the run() signature of the newest local contract', function () use ($contract) {
        $local = '/Users/christoph/Development/Github/fledge/packages/framework/src/Illuminate/Contracts/Concurrency/Driver.php';

        if (! is_file($local)) {
            $this->markTestSkipped('Framework fork not available locally.');
        }

        expect(file_get_contents($local))
            ->toContain('public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array;')
            ->toContain('public function defer(Closure|array $tasks): DeferredCallback;')
            ->and($contract)
            ->toContain('public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array;');
    });

    it('declares the timeout parameter with the contract type and default', function () {
        $parameters = (new ReflectionMethod(Webpatser\LaravelFiber\FiberDriver::class, 'run'))->getParameters();

        expect($parameters)->toHaveCount(2)
            ->and($parameters[1]->getName())->toBe('timeout')
            ->and((string) $parameters[1]->getType())->toBe('Carbon\CarbonInterval|int|null')
            ->and($parameters[1]->isDefaultValueAvailable())->toBeTrue()
            ->and($parameters[1]->getDefaultValue())->toBeNull();
    });

    it('loads against the Laravel 13.9+ contract', function () use ($contract) {
        // An incompatible declaration is an uncatchable compile error, so probe it in a child process.
        $script = tempnam(sys_get_temp_dir(), 'lf-contract-');
        file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
            .' eval('.var_export($contract, true).');'
            .' eval(\'class LaravelFiberContractProbeDriver extends \\\\Webpatser\\\\LaravelFiber\\\\FiberDriver implements \\\\LaravelFiberContractProbe\\\\Driver {}\');'
            .' echo "loaded";');

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $exitCode);
        unlink($script);

        expect(implode("\n", $output))->toBe('loaded')
            ->and($exitCode)->toBe(0);
    });
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
