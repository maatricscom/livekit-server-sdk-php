<?php

declare(strict_types=1);

namespace LiveKit\Grants;

/**
 * The `sip` claim. `admin` grants all SIP features; `call` grants outbound dialing.
 */
final readonly class SIPGrant
{
    public function __construct(
        public bool $admin = false,
        public bool $call = false,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        $grant = [];

        if ($this->admin) {
            $grant['admin'] = true;
        }

        if ($this->call) {
            $grant['call'] = true;
        }

        return $grant;
    }
}
