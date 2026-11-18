# Laravel Fiber

A Fiber-based concurrency driver for Laravel's `Concurrency` facade. Uses the [Revolt](https://revolt.run) event loop and [amphp](https://amphp.org) to run tasks with real async I/O, not just sequential execution wrapped in Fibers.

## How it works

The driver runs an inline Revolt event loop within the `run()` call. No background process or daemon required.

```
Concurrency::driver('fiber')->run([task1, task2, task3])
  → spawn 3 Fibers via Amp\async()
  → Amp\Future\awaitAll() starts temporary event loop
  → Fibers interleave on I/O suspension
  → all done → event loop stops → return results
```

When tasks use amphp async drivers (HTTP, MySQL, Redis), they genuinely run concurrently: each Fiber suspends on I/O and others execute in the meantime. Tasks using standard blocking PHP calls run sequentially, which is safe and expected.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require webpatser/laravel-fiber
```

The service provider is auto-discovered.

## Usage

### As explicit driver

```php
use Illuminate\Support\Facades\Concurrency;

$results = Concurrency::driver('fiber')->run([
    fn () => file_get_contents('https://api.example.com/users'),
    fn () => file_get_contents('https://api.example.com/posts'),
]);
```

### As default driver

Set in `config/concurrency.php`:

```php
'default' => 'fiber',
```

Then use the facade directly:

```php
$results = Concurrency::run([
    fn () => doSomething(),
    fn () => doSomethingElse(),
]);
```

### With async I/O (real concurrency)

Install amphp drivers for concurrent I/O:

```bash
composer require amphp/http-client  # Async HTTP
composer require amphp/mysql        # Async MySQL
composer require amphp/redis        # Async Redis
```

```php
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

$client = HttpClientBuilder::buildDefault();

// These 3 HTTP requests run concurrently, total time ≈ slowest request
$results = Concurrency::driver('fiber')->run([
    fn () => $client->request(new Request('https://api1.example.com'))->getBody()->buffer(),
    fn () => $client->request(new Request('https://api2.example.com'))->getBody()->buffer(),
    fn () => $client->request(new Request('https://api3.example.com'))->getBody()->buffer(),
]);
```

### Deferred execution

```php
Concurrency::driver('fiber')->defer([
    fn () => Log::info('Background task 1'),
    fn () => Log::info('Background task 2'),
]);
```

### Results, failures and timeouts

`run()` returns results keyed and ordered like the tasks you passed in, whatever order they finish in.

When tasks fail, `run()` waits for every task to settle and then throws the failure that comes first in task order, like Laravel's process driver. A failing task does not cancel its siblings: they run to completion, and the first failure in task order is rethrown after all tasks have finished.

`run()` accepts the optional `$timeout` argument of Laravel's Concurrency driver contract: an `int` (seconds) or a `CarbonInterval`. It bounds the whole call and, when it fires, throws `Amp\CancelledException` with an `Amp\TimeoutException` as its previous exception. Laravel's process driver throws `Illuminate\Process\Exceptions\ProcessTimedOutException` instead, applies the timeout per process and kills the process; Laravel's sync driver ignores the timeout. Here the timeout stops tasks only cooperatively (see below). A `run()` with a timeout keeps the event loop alive until the timeout fires or the call ends, so a call whose tasks all wait on something that never completes ends with the timeout exception.

```php
use Carbon\CarbonInterval;

$results = Concurrency::driver('fiber')->run([
    fn () => slowTaskA(),
    fn () => slowTaskB(),
], timeout: 5); // or CarbonInterval::seconds(5)
```

The process driver kills its child processes on a timeout. A fiber cannot be killed from outside, so here cancellation is cooperative: when the timeout fires the driver requests the cancellation of every task, which a task reads with `Webpatser\LaravelFiber\TaskCancellation::current()` (null outside a task). A task that passes it to its awaits, or calls `throwIfRequested()` in a loop, stops there and unwinds through its `finally` blocks:

```php
use Webpatser\LaravelFiber\TaskCancellation;

Concurrency::driver('fiber')->run([
    function () use ($client, $request) {
        $cancellation = TaskCancellation::current();

        Amp\delay(10, cancellation: $cancellation); // stopped at the timeout
        $response = $client->request($request, $cancellation); // amphp calls taking a Cancellation
    },
], timeout: 5);
```

CPU-bound code, blocking calls and awaits that do not receive the cancellation are not stopped: such a task finishes in the background and its result is discarded. A nested `run()` gives its tasks a cancellation of their own, also requested when the enclosing task's is.

The `$timeout` parameter matches the contract of Laravel 13.9 and later, and the driver also works with earlier 13.x releases. `composer.json` currently allows `illuminate/contracts` and `illuminate/support` `^11.0 || ^12.0 || ^13.0`.

## Driver comparison

| | ProcessDriver | ForkDriver | SyncDriver | **FiberDriver** |
|---|---|---|---|---|
| Shared memory | No (serialize) | No (fork) | Yes | **Yes** |
| Overhead | High (child processes) | Medium (pcntl_fork) | None | **Minimal** |
| Web requests | Yes | No (CLI only) | Yes | **Yes** |
| True concurrency | Yes (multi-process) | Yes (multi-process) | No | **Cooperative I/O** |
| Non-serializable closures | No | No | Yes | **Yes** |
| External dependencies | SerializableClosure | spatie/fork | None | **revolt + amphp** |

## When to use

- **FiberDriver**: I/O-bound tasks (API calls, database queries, cache operations) where you want concurrency without process overhead
- **ProcessDriver**: CPU-bound tasks that need true parallelism across cores
- **ForkDriver**: CLI-only parallel execution
- **SyncDriver**: Testing and development

## License

MIT
