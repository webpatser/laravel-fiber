# Laravel Fiber

A Fiber-based concurrency driver for Laravel's `Concurrency` facade. Uses the [Revolt](https://revolt.run) event loop and [amphp](https://amphp.org) to run tasks with real async I/O — not just sequential execution wrapped in Fibers.

## How it works

The driver runs an inline Revolt event loop within the `run()` call. No background process or daemon required.

```
Concurrency::driver('fiber')->run([task1, task2, task3])
  → spawn 3 Fibers via Amp\async()
  → Amp\Future\await() starts temporary event loop
  → Fibers interleave on I/O suspension
  → all done → event loop stops → return results
```

When tasks use amphp async drivers (HTTP, MySQL, Redis), they genuinely run concurrently — each Fiber suspends on I/O and others execute in the meantime. Tasks using standard blocking PHP calls run sequentially, which is safe and expected.

## Requirements

- PHP 8.1+
- Laravel 13.0+

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

// These 3 HTTP requests run concurrently — total time ≈ slowest request
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
