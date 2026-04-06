<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

// No base TestCase needed — all tests are pure unit tests
// that instantiate the FiberDriver directly.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeWithinDuration', function (float $maxSeconds) {
    return $this->toBeLessThan($maxSeconds);
});
