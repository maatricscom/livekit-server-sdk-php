<?php

declare(strict_types=1);

namespace LiveKit\Grants;

/**
 * The `agent` claim, for LiveKit Cloud agent management.
 */
final readonly class AgentGrant
{
    public function __construct(
        public bool $admin = false,
        public bool $simulationAdmin = false,
        public bool $databaseAdmin = false,
        public bool $dispatchAdmin = false,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        $grant = [];

        foreach ([
            'admin' => $this->admin,
            'simulationAdmin' => $this->simulationAdmin,
            'databaseAdmin' => $this->databaseAdmin,
            'dispatchAdmin' => $this->dispatchAdmin,
        ] as $key => $value) {
            if ($value) {
                $grant[$key] = true;
            }
        }

        return $grant;
    }
}
