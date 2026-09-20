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
     * ZzEnvLeakProbeTest asserts that no test leaked an environment variable, which
     * it can only do from last place. PHPUnit's default order is alphabetical and the
     * Zz prefix is what puts it there; any explicit executionOrder takes that away
     * and leaves the probe green while checking nothing. Its own docblock warns
     * against renaming the class. Nothing warned against the config.
     */
    public function test_phpunit_execution_order_is_left_at_its_default(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__) . '/phpunit.xml.dist');

        self::assertDoesNotMatchRegularExpression(
            '/\bexecutionOrder(Type)?\s*=/',
            $xml,
            'ZzEnvLeakProbeTest has to run last, which only the default alphabetical order guarantees.'
        );
    }

    /**
     * The probe has to run last, and two things decide that: PHPUnit's execution
     * order, guarded above, and where the file sorts. PHPUnit discovers a
     * <directory> by relative path, so `Zz` is what puts it after everything --
     * verified against `--list-tests`, which returns these 29 classes in exactly
     * this order. A test file added under a path sorting after it would take the
     * last slot and leave the probe checking a suite that had not finished.
     */
    public function test_the_environment_probe_is_the_last_test_in_the_unit_suite(): void
    {
        $root = dirname(__DIR__) . '/tests';
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            // phpunit.xml.dist excludes these two from the unit suite.
            if (str_starts_with($relative, 'Integration/') || str_starts_with($relative, 'MockServer/')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        self::assertSame('ZzEnvLeakProbeTest.php', end($files), sprintf(
            'ZzEnvLeakProbeTest must sort last so it runs after every other test; %s now sorts after it.',
            end($files)
        ));
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

    /**
     * CI runs the mock-server suite with --fail-on-skipped, and that is only safe
     * while a skip there can mean one thing.
     *
     * The suite has exactly one skip: MockServerTestCase, when
     * LIVEKIT_TEST_SERVER_URL or LIVEKIT_TEST_SERVER_SECRET is missing. Because
     * that is the only one, every skip in CI is a broken environment, which is
     * precisely what the flag should fail on -- otherwise a job whose service
     * container never came up reports success having asserted nothing.
     *
     * The integration suite is the opposite case and deliberately does not use the
     * flag: skipIfUnavailable() is called two dozen times there, because a
     * deployment without SIP or egress is a valid one to run against and a skip is
     * the right answer. That job checks its environment in the shell instead.
     *
     * So a feature-conditional skip added to tests/MockServer/ would quietly turn
     * CI's flag from a guard into a false alarm. This fails if one appears.
     */
    public function test_the_mock_server_suite_skips_only_for_a_missing_environment(): void
    {
        $root = dirname(__DIR__) . '/tests/MockServer';
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
            $source = (string) file_get_contents($file->getPathname());

            // The one sanctioned skip, and the helper that makes a skip conditional
            // on what a deployment offers -- which has no meaning against a mock.
            if (str_contains($source, 'skipIfUnavailable')) {
                $offenders[] = $relative . ' calls skipIfUnavailable()';
            }

            if (str_contains($source, 'markTestSkipped') && $relative !== 'tests/MockServer/Support/MockServerTestCase.php') {
                $offenders[] = $relative . ' calls markTestSkipped()';
            }
        }

        sort($offenders);

        self::assertSame([], $offenders, implode("\n", [
            'CI runs this suite with --fail-on-skipped, which assumes every skip means the environment is broken.',
            'These would skip for another reason, so the flag would fail the job for a healthy run:',
            ...$offenders,
        ]));
    }
}
