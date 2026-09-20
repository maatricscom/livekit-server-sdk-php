<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * Implemented by every exception this SDK throws, so callers can catch
 * `LiveKitException` to handle anything originating from the SDK.
 */
interface LiveKitException extends \Throwable
{
}
