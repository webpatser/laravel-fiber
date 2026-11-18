<?php

namespace Webpatser\LaravelFiber;

use Amp\Cancellation;
use Closure;
use Fiber;
use WeakMap;

/**
 * The cancellation of the fiber-driver task running in the current fiber.
 *
 * A fiber cannot be stopped from outside; it can only be cancelled at a point
 * where it is suspended in an await that observes a cancellation. FiberDriver
 * gives every task one, requested when the run's timeout fires (and, for a
 * nested run, when the enclosing task's cancellation is requested). A task
 * passes current() to its own awaits (Amp\delay(), Future::await(), and amphp
 * HTTP, socket and database calls that take a Cancellation) or checks it in
 * loops (throwIfRequested()); a task that does neither keeps running as before.
 *
 * - Only the task's own fiber sees it: fibers a task starts itself (async())
 *   get null unless the task hands the cancellation over.
 * - It stays requested after the run returns or throws, so keeping it past
 *   the task's end is harmless but not meaningful.
 */
final class TaskCancellation
{
    /**
     * The number of tasks running with a cancellation right now; zero outside the fiber driver.
     */
    private static int $active = 0;

    /**
     * @var WeakMap<Fiber, Cancellation>|null
     */
    private static ?WeakMap $cancellations = null;

    /**
     * The cancellation of the task running in the current fiber, or null outside a fiber-driver task.
     */
    public static function current(): ?Cancellation
    {
        if (self::$active === 0 || ($fiber = Fiber::getCurrent()) === null) {
            return null;
        }

        return self::$cancellations[$fiber] ?? null;
    }

    /**
     * Run a task with the given cancellation as its ambient one.
     *
     * @internal
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $task
     * @return TReturn
     */
    public static function run(Cancellation $cancellation, Closure $task): mixed
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $task();
        }

        self::$cancellations ??= new WeakMap;
        self::$cancellations[$fiber] = $cancellation;
        self::$active++;

        try {
            return $task();
        } finally {
            // Revolt reuses callback fibers, so the entry must go now, not with the fiber.
            unset(self::$cancellations[$fiber]);
            self::$active--;
        }
    }
}
