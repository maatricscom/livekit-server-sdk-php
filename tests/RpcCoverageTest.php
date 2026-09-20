<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\LiveKitAPI;
use LiveKit\Services\ServiceBase;
use LiveKit\Tests\MockServer\RpcSweepTest;
use LiveKit\Tests\Support\TestCase;

/**
 * Holds RpcSweepTest to its claim of calling every RPC.
 *
 * Without this, adding a method to a service client and forgetting the sweep
 * entry costs nothing: the suite stays green and the new RPC's grant is never
 * checked against the server's permission table until a user hits it. protoc
 * emits no service code, so a new RPC upstream leaves no trace in src/Proto
 * either -- nothing else in this repository would notice.
 *
 * It lives in the unit suite on purpose. The sweep itself skips when there is no
 * mock server, and a coverage guard that skips with it would be no guard at all.
 * Reflection needs no server.
 */
final class RpcCoverageTest extends TestCase
{
    /**
     * Every RPC method the SDK exposes, as "facadeProperty.methodName" -- the same
     * shape the sweep names its cases. Derived from LiveKitAPI rather than from a
     * hand-kept list, so a new service client is covered the moment it is wired in.
     *
     * @return list<string>
     */
    private function expectedCases(): array
    {
        $cases = [];

        foreach ((new \ReflectionClass(LiveKitAPI::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $className = $type->getName();

            // A property could be typed with a builtin or an interface; only a real
            // class can be reflected over for its methods.
            if (!class_exists($className)) {
                continue;
            }

            $client = new \ReflectionClass($className);

            if (!$client->isSubclassOf(ServiceBase::class)) {
                continue;
            }

            foreach ($client->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // Only the client's own RPC methods: not the constructor, and not
                // the credential handling every client inherits from ServiceBase.
                if ($method->isConstructor() || $method->isStatic()) {
                    continue;
                }

                if ($method->getDeclaringClass()->getName() !== $client->getName()) {
                    continue;
                }

                $cases[] = $property->getName() . '.' . $method->getName();
            }
        }

        sort($cases);

        return $cases;
    }

    /** @return list<string> */
    private function sweptCases(): array
    {
        $cases = array_keys(iterator_to_array(RpcSweepTest::rpcs()));
        $cases = array_map(strval(...), $cases);
        sort($cases);

        return $cases;
    }

    public function test_the_sweep_covers_every_rpc_every_service_client_exposes(): void
    {
        $missing = array_values(array_diff($this->expectedCases(), $this->sweptCases()));

        self::assertSame([], $missing, sprintf(
            "These RPCs have no entry in RpcSweepTest::rpcs(), so nothing checks their grant or wire "
            . "format against a real server:\n  %s",
            implode("\n  ", $missing)
        ));
    }

    public function test_the_sweep_names_no_rpc_that_no_longer_exists(): void
    {
        $stale = array_values(array_diff($this->sweptCases(), $this->expectedCases()));

        self::assertSame([], $stale, sprintf(
            "These sweep entries name methods no service client has any more:\n  %s",
            implode("\n  ", $stale)
        ));
    }
}
