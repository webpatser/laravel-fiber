<?php

namespace Webpatser\LaravelFiber;

use Closure;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;

use function Amp\async;
use function Amp\Future\await;
use function Illuminate\Support\defer;

class FiberDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks): array
    {
        $tasks = Arr::wrap($tasks);

        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = async($task);
        }

        return await($futures);
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return defer(fn () => $this->run($tasks));
    }
}
