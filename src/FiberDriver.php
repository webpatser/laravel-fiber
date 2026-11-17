<?php

namespace Webpatser\LaravelFiber;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;

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

        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = async($task);
        }

        $cancellation = match (true) {
            $timeout instanceof CarbonInterval => new TimeoutCancellation($timeout->totalSeconds),
            is_int($timeout) => new TimeoutCancellation($timeout),
            default => null,
        };

        // Let every task settle, like the stock ProcessDriver waits for the whole pool. The timeout
        // bounds the whole call: awaitAll() throws CancelledException once it fires.
        try {
            [$errors, $values] = awaitAll($futures, $cancellation);
        } catch (CancelledException $e) {
            // A running fiber cannot be stopped from outside; drop the tasks still in flight so a
            // later failure does not surface as an UnhandledFutureError.
            foreach ($futures as $future) {
                $future->ignore();
            }

            throw $e;
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
