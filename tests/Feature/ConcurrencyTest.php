<?php

use Webpatser\LaravelFiber\FiberDriver;

beforeEach(fn () => $this->driver = new FiberDriver);

describe('concurrent execution', function () {
    it('runs tasks concurrently (timing proof)', function () {
        $start = hrtime(true);

        $results = $this->driver->run([
            function () {
                Amp\delay(0.1);

                return 'a';
            },
            function () {
                Amp\delay(0.1);

                return 'b';
            },
            function () {
                Amp\delay(0.1);

                return 'c';
            },
        ]);

        $elapsed = (hrtime(true) - $start) / 1e9;

        // 3 tasks x 100ms each — if sequential would take 300ms+
        // Concurrent should finish well under 250ms
        expect($results)->toBe(['a', 'b', 'c'])
            ->and($elapsed)->toBeWithinDuration(0.25);
    });

    it('runs 10 concurrent tasks', function () {
        $start = hrtime(true);

        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $n = $i;
            $tasks[$n] = function () use ($n) {
                Amp\delay(0.05);

                return "result-{$n}";
            };
        }

        $results = $this->driver->run($tasks);

        $elapsed = (hrtime(true) - $start) / 1e9;

        expect($results)->toHaveCount(10)
            ->and($results[0])->toBe('result-0')
            ->and($results[9])->toBe('result-9')
            ->and($elapsed)->toBeWithinDuration(0.15); // 10x50ms concurrent < 150ms
    });

    it('interleaves CPU and IO tasks', function () {
        $order = [];

        $this->driver->run([
            function () use (&$order) {
                $order[] = 'io-start';
                Amp\delay(0.05);
                $order[] = 'io-end';
            },
            function () use (&$order) {
                // This runs while the IO task is suspended
                $order[] = 'cpu';
            },
        ]);

        // CPU task should run while IO task is delayed
        expect($order[0])->toBe('io-start')
            ->and($order[1])->toBe('cpu')
            ->and($order[2])->toBe('io-end');
    });
});

describe('error handling under concurrency', function () {
    it('propagates exception even when other tasks succeed', function () {
        try {
            $this->driver->run([
                function () {
                    Amp\delay(0.01);

                    return 'ok';
                },
                fn () => throw new RuntimeException('failed'),
                function () {
                    Amp\delay(0.01);

                    return 'also ok';
                },
            ]);

            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('failed');
        } catch (Amp\CompositeException $e) {
            // amphp may wrap in CompositeException
            expect($e->getReasons())->not->toBeEmpty();
        }
    });

    it('handles exceptions in concurrent delayed tasks', function () {
        $this->driver->run([
            function () {
                Amp\delay(0.01);
                throw new RuntimeException('delayed boom');
            },
        ]);
    })->throws(RuntimeException::class, 'delayed boom');
});

describe('real-world patterns', function () {
    it('aggregates results from parallel computations', function () {
        $results = $this->driver->run([
            'sum' => function () {
                Amp\delay(0.01);

                return array_sum(range(1, 100));
            },
            'product' => function () {
                Amp\delay(0.01);

                return array_product(range(1, 10));
            },
            'count' => function () {
                Amp\delay(0.01);

                return count(range(1, 1000));
            },
        ]);

        expect($results['sum'])->toBe(5050)
            ->and($results['product'])->toBe(3628800)
            ->and($results['count'])->toBe(1000);
    });

    it('works with closures that capture complex state', function () {
        $config = ['timeout' => 30, 'retries' => 3];
        $logger = new class {
            public array $messages = [];

            public function log(string $msg): void
            {
                $this->messages[] = $msg;
            }
        };

        $this->driver->run([
            function () use ($config, $logger) {
                $logger->log("timeout is {$config['timeout']}");
            },
            function () use ($config, $logger) {
                $logger->log("retries is {$config['retries']}");
            },
        ]);

        expect($logger->messages)->toHaveCount(2);
    });
});
