# Changelog

All notable changes to Laravel Fiber will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-18

### Added
- `FiberDriver::run()` accepts the `timeout` parameter (`CarbonInterval|int|null`) the `Concurrency` driver contract declares since Laravel 13.9, so the class loads on current releases.
- Cooperative task cancellation: new `TaskCancellation::current()` returns the cancellation of the task running in the current fiber (or `null` outside a fiber-driver task). It is requested when the timeout fires and, in a nested run, when the enclosing task's cancellation is requested. A task that passes it to its awaits or checks `throwIfRequested()` unwinds through its own `finally` blocks; a task that does neither keeps running as before. Fibers a task starts itself get `null` unless the task hands the cancellation over.

### Changed
- **Behaviour change:** `run()` waits for every task to settle (it used `await()`, which returned on the first failure). A failing task no longer cancels its siblings; they run to completion.
- **Behaviour change:** results are returned keyed and ordered like the input, not in completion order, and the first failure in task order is rethrown, as the process driver does.
- **Behaviour change:** the timeout bounds the whole call, not each task.
- **Behaviour change:** when the timeout fires, `run()` throws `Amp\CancelledException` (with `Amp\TimeoutException` as the previous exception). Tasks still in flight are dropped so a later failure does not surface as an `UnhandledFutureError`.
- The timeout uses a referenced event loop timer, so the loop stays alive until it fires and a call whose tasks all wait on something unreferenced still ends in `CancelledException`. A call that finishes early cancels the timer.

## [1.0.0] - 2026-04-06

Initial release.
