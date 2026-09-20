<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration\Support;

use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\ClientOptions;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that run against a real LiveKit deployment.
 *
 * Skipped unless LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET are all set,
 * so this suite never gates CI. It is the release gate: the mock server proves
 * this SDK puts something on the wire that LiveKit's *model* of its API accepts,
 * and only these tests prove a deployment does.
 *
 * Everything here runs against someone's real project, which shapes what it may
 * do. Two rules:
 *
 *   1. Anything created is deleted, including when an assertion fails — see
 *      cleanUpAfter(). A leaked room or ingress costs the project owner. The one
 *      exception is named where it happens: connectTwilioCall() provisions a
 *      room of its own that is registered too late to delete synchronously, and
 *      ConnectorIntegrationTest says why it is left to close itself.
 *   2. Nothing is called that places a call, starts a recording or incurs a
 *      charge. The RPCs left untested for that reason are named in the test that
 *      would otherwise cover them, rather than quietly skipped.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected LiveKitAPI $livekit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['LIVEKIT_URL', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                self::markTestSkipped(sprintf('%s is not set; skipping integration tests.', $name));
            }
        }

        $this->livekit = new LiveKitAPI();
    }

    /**
     * A second client speaking JSON instead of binary protobuf.
     *
     * Worth exercising against a real server because no official LiveKit SDK
     * sends either content type the way this one does: the others send JSON via
     * Twirp's JSON route, this one defaults to application/protobuf.
     */
    protected function jsonClient(): LiveKitAPI
    {
        return new LiveKitAPI(options: new ClientOptions(wireFormat: WireFormat::Json));
    }

    /** A name no other test run will collide with, and one a human can recognise. */
    /**
     * A client that does not fail over, for a call expected to fail.
     *
     * Failover replays a retryable failure across regions, and the regions it
     * reaches next can be slower to give up than the one first asked: measured
     * against a live Cloud project, the same failing call took 7.8 seconds with
     * this client and 177 with the default. A test that already knows the answer
     * should not pay that three times to hear it again.
     */
    protected function noFailoverClient(): LiveKitAPI
    {
        return new LiveKitAPI(options: new ClientOptions(failover: false));
    }

    protected function scratchName(string $what): string
    {
        return sprintf('php-sdk-it-%s-%s', $what, bin2hex(random_bytes(4)));
    }

    /**
     * Runs $body, then $cleanup, and reports the right failure when both fail.
     *
     * The subtlety this exists to get right: if $body throws and $cleanup also
     * throws, PHP replaces the first exception with the second, and the message
     * you see is "could not delete room" rather than the assertion that actually
     * failed. So a cleanup failure is only raised when $body succeeded; otherwise
     * it is written to STDERR, because a leaked resource on a real project is
     * still something the operator has to know about.
     *
     * @template T
     * @param  callable(): T $body
     * @param  callable(): mixed $cleanup  its return value is ignored; the RPCs used
     *                                     for cleanup answer with a response message
     * @return T
     */
    protected function cleanUpAfter(callable $body, callable $cleanup, string $describe): mixed
    {
        $bodySucceeded = false;

        try {
            $result = $body();
            $bodySucceeded = true;

            return $result;
        } finally {
            try {
                $cleanup();
            } catch (\Throwable $cleanupError) {
                if ($bodySucceeded) {
                    throw $cleanupError;
                }

                fwrite(STDERR, sprintf(
                    "Warning: failed to clean up %s after an earlier failure: %s%s",
                    $describe,
                    $cleanupError->getMessage(),
                    PHP_EOL
                ));
            }
        }
    }

    /**
     * Runs $probe, skipping the test when the deployment does not offer the
     * feature rather than failing it.
     *
     * SIP, egress and agent dispatch are provisioned per project: an open-source
     * server without SIP configured, or a Cloud project without it enabled, is a
     * perfectly valid deployment that simply cannot answer. That is not this
     * SDK failing, and a red suite for it would train people to ignore this
     * suite. A genuine error -- bad request, bad auth -- still fails.
     *
     * @template T
     * @param  callable(): T $probe
     * @return T
     */
    protected function skipIfUnavailable(callable $probe, string $feature): mixed
    {
        try {
            return $probe();
        } catch (TwirpException $e) {
            if (in_array($e->getTwirpCode(), [
                TwirpErrorCode::UNIMPLEMENTED,
                TwirpErrorCode::PERMISSION_DENIED,
                TwirpErrorCode::FAILED_PRECONDITION,
                TwirpErrorCode::UNAVAILABLE,
            ], true)) {
                self::markTestSkipped(sprintf(
                    '%s is not available on this deployment (%s: %s).',
                    $feature,
                    $e->getTwirpCode(),
                    $e->getMessage()
                ));
            }

            throw $e;
        }
    }

    /**
     * Asserts that a list rpc filtered by the id of a just-deleted object reports
     * that the object is gone.
     *
     * Deployments answer a filter that matches nothing in two different ways, and
     * both were measured against a live server rather than assumed: LiveKit Cloud
     * raises not_found for listIngress and listEgress but returns an empty list
     * for listRooms, while the mock server returns an empty list throughout. Both
     * answers mean the same thing, so this asserts the meaning instead of picking
     * one deployment's dialect and making the suite red on the other.
     *
     * This stays strict where it matters: an object that survived its delete comes
     * back in the list, which is neither outcome and still fails.
     *
     * @param callable(): array<mixed> $lookup
     */
    protected function assertGoneAfterDelete(callable $lookup, string $describe): void
    {
        try {
            self::assertSame([], $lookup(), sprintf('%s is still listed after its delete', $describe));
        } catch (TwirpException $e) {
            if ($e->getTwirpCode() !== TwirpErrorCode::NOT_FOUND) {
                throw $e;
            }

            // not_found is the other way "gone" is spelled, so this counts as the
            // assertion having been met rather than as a test with none.
            self::addToAssertionCount(1);
        }
    }
}
