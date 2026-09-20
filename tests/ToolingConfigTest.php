<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Tests\Support\TestCase;

/**
 * The supported PHP range is stated in composer.json and has to be repeated in the
 * static analyser's configuration, because PHPStan otherwise analyses against
 * whichever PHP happens to run it -- so a construct that only exists in the newest
 * supported version passes on a contributor's machine and breaks for a user on the
 * oldest. Two places, one fact; this is what keeps them the same fact.
 */
final class ToolingConfigTest extends TestCase
{
    public function test_phpstan_analyses_the_php_range_composer_declares(): void
    {
        $constraint = self::composerPhpConstraint();

        // fail() rather than an assertion on preg_match's return: the assertion would
        // hold, but leaves $m an unknown shape, so the reads below are unprovable.
        if (preg_match('/^\^(\d+)\.(\d+)$/', $constraint, $m) !== 1) {
            self::fail(sprintf('Expected a caret constraint like "^8.4", got "%s". Update this test with it.', $constraint));
        }

        $expected = ((int) $m[1]) * 10000 + ((int) $m[2]) * 100;

        self::assertSame($expected, self::phpstanPhpVersion()['min'], sprintf(
            'composer.json requires php %s, so phpstan.neon.dist must analyse from %d up.',
            $constraint,
            $expected
        ));
    }

    /**
     * A caret constraint admits every later minor, so the ceiling is the newest PHP
     * that exists rather than anything composer.json states. It moves by hand when a
     * new PHP is released and this package is tested on it.
     */
    public function test_phpstan_upper_bound_is_above_the_lower_one(): void
    {
        $range = self::phpstanPhpVersion();

        self::assertGreaterThan($range['min'], $range['max']);
    }

    /**
     * @return array{min: int, max: int}
     */
    private static function phpstanPhpVersion(): array
    {
        $neon = (string) file_get_contents(dirname(__DIR__) . '/phpstan.neon.dist');

        if (preg_match('/^\s+min:\s*(\d+)\s*$/m', $neon, $min) !== 1) {
            self::fail('phpstan.neon.dist declares no phpVersion.min');
        }

        if (preg_match('/^\s+max:\s*(\d+)\s*$/m', $neon, $max) !== 1) {
            self::fail('phpstan.neon.dist declares no phpVersion.max');
        }

        return ['min' => (int) $min[1], 'max' => (int) $max[1]];
    }

    private static function composerPhpConstraint(): string
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (! is_array($decoded) || ! isset($decoded['require']) || ! is_array($decoded['require'])) {
            self::fail('composer.json has no require section.');
        }

        $constraint = $decoded['require']['php'] ?? null;

        if (! is_string($constraint)) {
            self::fail('composer.json does not require a php version.');
        }

        return $constraint;
    }
}
