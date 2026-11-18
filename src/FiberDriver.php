<?php

namespace Webpatser\LaravelFiber;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\TimeoutException;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Future\awaitAll;
use function Illuminate\Support\defer;

class FiberDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $tasks = Arr::wrap($tasks);

        // Every task's ambient cancellation (see TaskCancellation): requested on the timeout, and
        // in a nested run also when the enclosing task's own cancellation is requested.
        $source = new DeferredCancellation;
        $taskCancellation = $source->getCancellation();
        $outer = TaskCancellation::current();
        $outerId = $outer?->subscribe(static fn (CancelledException $e) => $source->cancel($e->getPrevious() ?? $e));

        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = async(fn () => TaskCancellation::run($taskCancellation, $task));
        }

        $seconds = match (true) {
            $timeout instanceof CarbonInterval => $timeout->totalSeconds,
            is_int($timeout) => $timeout,
            default => null,
        };

        // A referenced timer, unlike TimeoutCancellation's, holds the event loop open until it fires,
        // so a call whose tasks all wait on something unreferenced still ends in CancelledException.
        $cancellation = null;
        $timerId = null;

        if ($seconds !== null) {
            $timeoutSource = new DeferredCancellation;
            $cancellation = $timeoutSource->getCancellation();
            $timerId = EventLoop::delay($seconds, static fn () => $timeoutSource->cancel(new TimeoutException));
        }

        // Let every task settle, like the stock ProcessDriver waits for the whole pool. The timeout
        // bounds the whole call: awaitAll() throws CancelledException once it fires.
        try {
            [$errors, $values] = awaitAll($futures, $cancellation);
        } catch (CancelledException $e) {
            // A running fiber cannot be stopped from outside. Tasks that pass their cancellation to
            // an await unwind through their own finally blocks; the others keep running. Drop the
            // tasks still in flight so a later failure does not surface as an UnhandledFutureError.
            $source->cancel($e->getPrevious() ?? $e);

            foreach ($futures as $future) {
                $future->ignore();
            }

            throw $e;
        } finally {
            // A call that finishes early must not keep the loop alive until the timeout.
            if ($timerId !== null) {
                EventLoop::cancel($timerId);
            }

            if ($outerId !== null) {
                $outer?->unsubscribe($outerId);
            }
        }

        $results = [];

        // awaitAll() fills both arrays in completion order; rethrow and return in task order instead.
        foreach (array_keys($futures) as $key) {
            if (array_key_exists($key, $errors)) {
                throw $errors[$key];
            }

            $results[$key] = $values[$key];
        }

        return $results;
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return defer(fn () => $this->run($tasks));
    }
}
