<?php

declare(strict_types=1);

namespace LiveKit\Enums;

use LiveKit\Exceptions\ConfigurationException;

/**
 * Checks an integer against a generated protobuf enum.
 *
 * protoc emits enums as classes of integer constants, and the generated setters
 * take any integer at all: setInputType(99) and setInputType(-1) are both
 * accepted, encoded, and sent. The server then reads a value its own enum does
 * not define, and what it does with it is its business — nothing on the way
 * there says anything is wrong.
 *
 * PHP is the language most exposed to this. Go, Rust and Kotlin have real enum
 * types, and TypeScript checks at compile time; here the only thing standing
 * between a caller and a wrong integer is a check like this one. The realistic
 * mistake is not an invented number but a constant from the neighbouring enum —
 * SIPTransport where SIPHeaderOptions was wanted, say, both plain ints.
 *
 * The accepted set is read from the generated class, so it follows the pinned
 * protocol rather than a list written here that would drift from it.
 */
final class ProtoEnum
{
    /**
     * @param class-string $enum
     *
     * @return array<string, int> constant name => value, as protoc generated them
     */
    public static function names(string $enum): array
    {
        /** @var array<string, int> $constants */
        $constants = (new \ReflectionClass($enum))->getConstants();

        return $constants;
    }

    /**
     * Returns $value unchanged, or throws if the enum does not define it.
     *
     * A zero value is accepted: in most LiveKit enums it is a meaningful default
     * (SIP_TRANSPORT_AUTO, RELIABLE, RTMP_INPUT), not an "unset" marker.
     *
     * @param class-string $enum
     * @param string       $field the caller-facing name, so the message says what to fix
     */
    public static function check(string $enum, int $value, string $field): int
    {
        $names = self::names($enum);

        if (!in_array($value, $names, true)) {
            throw ConfigurationException::unknownEnumValue($field, $value, $enum, array_keys($names));
        }

        return $value;
    }
}
