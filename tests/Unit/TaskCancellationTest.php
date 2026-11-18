<?php

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutException;
use Carbon\CarbonInterval;
use Revolt\EventLoop;
use Webpatser\LaravelFiber\FiberDriver;
use Webpatser\LaravelFiber\TaskCancellation;

beforeEach(fn () => $this->driver = new FiberDriver);

/**
 * Run the callback, collect dropped futures, and return the messages the event loop reported as unhandled.
 *
 * The callback awaits its own tasks' settlement; this only adds the tick that reports dropped futures.
 *
 * @return list<string>
 */
function captureLoopErrors(callable $callback): array
{
    $errors = [];
    $previous = EventLoop::getErrorHandler();

    EventLoop::setErrorHandler(function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    });

    try {
        $callback();

        gc_collect_cycles();
        Amp\delay(0.01);
    } finally {
        EventLoop::setErrorHandler($previous);
    }

    return $errors;
}

it('stops a task that passes the cancellation to delay() at the timeout', function () {
    $log = [];
    $thrown = null;

    $unhandled = captureLoopErrors(function () use (&$log, &$thrown) {
        $settled = new DeferredFuture;

        try {
            $this->driver->run([
                function () use (&$log, $settled) {
                    try {
                        Amp\delay(1, cancellation: TaskCancellation::current());
                        $log[] = 'after';
                    } catch (CancelledException $e) {
                        $log[] = 'cancelled';

                        throw $e;
                    } finally {
                        $log[] = 'finally';
                        $settled->complete();
                    }
                },
            ], CarbonInterval::milliseconds(30));
        } catch (CancelledException $e) {
            $thrown = $e;
        }

        // The task unwinds on the next loop ticks, long before its delay would end.
        $settled->getFuture()->await();
        $log[] = 'checked';
    });

    expect($thrown)->toBeInstanceOf(CancelledException::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(TimeoutException::class)
        ->and($log)->toBe(['cancelled', 'finally', 'checked'])
        ->and($unhandled)->toBe([]);
});

it('stops a task awaiting a future with the cancellation at the timeout', function () {
    $log = [];

    $unhandled = captureLoopErrors(function () use (&$log) {
        // Nothing else holds the event loop open: the timeout itself must keep it alive until it fires.
        $never = new DeferredFuture;
        $settled = new DeferredFuture;

        try {
            $this->driver->run([
                function () use (&$log, $never, $settled) {
                    try {
                        $never->getFuture()->await(TaskCancellation::current());
                        $log[] = 'after';
                    } finally {
                        $log[] = 'finally';
                        $settled->complete();
                    }
                },
            ], CarbonInterval::milliseconds(30));
            $this->fail('Expected the timeout to fire.');
        } catch (CancelledException $e) {
            expect($e->getPrevious())->toBeInstanceOf(TimeoutException::class);
        }

        $settled->getFuture()->await();
        $log[] = 'checked';
    });

    expect($log)->toBe(['finally', 'checked'])
        ->and($unhandled)->toBe([]);
});

it('lets a task that ignores the cancellation keep running and discards its result', function () {
    $finished = false;

    $unhandled = captureLoopErrors(function () use (&$finished) {
        $settled = new DeferredFuture;

        try {
            $this->driver->run([
                function () use (&$finished, $settled) {
                    try {
                        Amp\delay(0.08);
                        $finished = true;

                        return 'discarded';
                    } finally {
                        $settled->complete();
                    }
                },
            ], CarbonInterval::milliseconds(20));
            $this->fail('Expected the timeout to fire.');
        } catch (CancelledException $e) {
            expect($e->getPrevious())->toBeInstanceOf(TimeoutException::class)
                ->and($finished)->toBeFalse();
        }

        $settled->getFuture()->await();
    });

    expect($finished)->toBeTrue()
        ->and($unhandled)->toBe([]);
});

it('does not request the cancellation while the run finishes inside the timeout', function () {
    $results = $this->driver->run([
        fn () => TaskCancellation::current()?->isRequested(),
        function () {
            Amp\delay(0.01, cancellation: TaskCancellation::current());

            return 'done';
        },
    ], CarbonInterval::seconds(5));

    expect($results)->toBe([false, 'done']);
});

it('returns null outside a task', function () {
    expect(TaskCancellation::current())->toBeNull()
        ->and(Amp\async(fn () => TaskCancellation::current())->await())->toBeNull();

    $inside = $this->driver->run([fn () => TaskCancellation::current()]);

    expect($inside[0])->toBeInstanceOf(Cancellation::class)
        ->and(TaskCancellation::current())->toBeNull();
});

it('gives a nested run its own cancellation that the outer one cancels', function () {
    $log = [];
    $seen = [];

    $unhandled = captureLoopErrors(function () use (&$log, &$seen) {
        $settled = new DeferredFuture;

        try {
            $this->driver->run([
                function () use (&$log, &$seen, $settled) {
                    $seen['outer'] = TaskCancellation::current();

                    try {
                        (new FiberDriver)->run([
                            function () use (&$log, &$seen) {
                                $seen['inner'] = TaskCancellation::current();

                                try {
                                    Amp\delay(1, cancellation: TaskCancellation::current());
                                    $log[] = 'inner after';
                                } finally {
                                    $log[] = 'inner finally';
                                }
                            },
                        ]);
                        $log[] = 'outer after';
                    } finally {
                        $log[] = 'outer finally';
                        $settled->complete();
                    }
                },
            ], CarbonInterval::milliseconds(30));
            $this->fail('Expected the timeout to fire.');
        } catch (CancelledException) {
            //
        }

        $settled->getFuture()->await();
    });

    expect($seen['inner'])->not->toBe($seen['outer'])
        ->and($seen['inner']->isRequested())->toBeTrue()
        ->and($log)->toBe(['inner finally', 'outer finally'])
        ->and($unhandled)->toBe([]);
});

it('does not cancel the outer task when an inner run times out', function () {
    $results = $this->driver->run([
        function () {
            try {
                (new FiberDriver)->run([
                    fn () => Amp\delay(1, cancellation: TaskCancellation::current()),
                ], CarbonInterval::milliseconds(20));
            } catch (CancelledException) {
                //
            }

            Amp\delay(0.03);

            return TaskCancellation::current()->isRequested();
        },
    ], CarbonInterval::seconds(5));

    expect($results)->toBe([false]);
});

it('stops a task that polls throwIfRequested() in a loop at the timeout', function () {
    $iterations = 0;
    $log = [];
    $thrown = null;

    $unhandled = captureLoopErrors(function () use (&$iterations, &$log, &$thrown) {
        $settled = new DeferredFuture;

        try {
            $this->driver->run([
                function () use (&$iterations, &$log, $settled) {
                    try {
                        while (true) {
                            TaskCancellation::current()->throwIfRequested();
                            $iterations++;
                            Amp\delay(0.005);
                        }
                    } catch (CancelledException $e) {
                        $log[] = 'cancelled';

                        throw $e;
                    } finally {
                        $settled->complete();
                    }
                },
            ], CarbonInterval::milliseconds(30));
            $this->fail('Expected the timeout to fire.');
        } catch (CancelledException $e) {
            $thrown = $e;
        }

        $settled->getFuture()->await();
    });

    expect($thrown->getPrevious())->toBeInstanceOf(TimeoutException::class)
        ->and($log)->toBe(['cancelled'])
        ->and($iterations)->toBeGreaterThan(0)
        ->and($unhandled)->toBe([]);
});

it('does not cancel a sibling when another task fails', function () {
    $error = null;
    $sibling = [];

    try {
        $this->driver->run([
            fn () => throw new RuntimeException('boom'),
            function () use (&$sibling) {
                Amp\delay(0.02, cancellation: TaskCancellation::current());
                $sibling[] = TaskCancellation::current()->isRequested();
                $sibling[] = 'done';
            },
        ], CarbonInterval::seconds(5));
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect($error)->toBe('boom')
        ->and($sibling)->toBe([false, 'done']);
});
