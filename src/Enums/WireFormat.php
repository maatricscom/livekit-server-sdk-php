<?php

declare(strict_types=1);

namespace LiveKit\Enums;

/**
 * How the SDK encodes Twirp request and response bodies.
 *
 * Protobuf is the default: it preserves unknown fields, so the SDK keeps working
 * when LiveKit adds a proto field, and it avoids proto3-JSON encoding quirks.
 * Json exists for debugging and for parity with the Node SDK's wire traffic.
 */
enum WireFormat: string
{
    case Protobuf = 'protobuf';
    case Json = 'json';

    public function contentType(): string
    {
        return match ($this) {
            self::Protobuf => 'application/protobuf',
            self::Json => 'application/json',
        };
    }
}
