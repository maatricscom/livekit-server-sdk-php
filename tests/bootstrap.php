<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Captured once per process, before any test runs. Capturing this in
// setUpBeforeClass() would be too late: that runs per test class, so by the
// time the probe's own class starts, an earlier class may already have
// clobbered the variable — and the probe would then assert the clobbered
// value against itself and never fail.
\LiveKit\Tests\Support\TestCase::captureEnvironmentBaseline();
