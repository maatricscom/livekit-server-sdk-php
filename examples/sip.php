<?php

declare(strict_types=1);

/**
 * Sets up outbound SIP: a trunk pointing at your provider, and a dispatch rule
 * that puts each inbound caller in a room of their own. Then lists both and
 * deletes what it created.
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... \
 *   LIVEKIT_API_SECRET=... \
 *   SIP_ADDRESS=sip.provider.example \
 *   SIP_NUMBER=+15551234567 \
 *   SIP_USERNAME=... SIP_PASSWORD=... \
 *   php examples/sip.php
 *
 * Deliberately *not* shown: createSipParticipant(). That one dials, and an
 * example someone runs to see what happens should not place a phone call. The
 * shape is `$livekit->sip->createSipParticipant($trunkId, $number, $room, ...)`,
 * and it throws SipCallError with getSipStatusCode() when the far end refuses —
 * 486 for busy, 480 for unavailable.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleIndividual;

function env(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        fwrite(STDERR, sprintf('%s is not set.%s', $name, PHP_EOL));
        exit(1);
    }

    return $value;
}

$suffix = bin2hex(random_bytes(3));

try {
    $livekit = new LiveKitAPI();

    echo 'Creating an outbound trunk...' . PHP_EOL;

    $trunk = $livekit->sip->createSipOutboundTrunk(
        'php-sdk-example-' . $suffix,
        env('SIP_ADDRESS'),
        [env('SIP_NUMBER')],
        new CreateSipOutboundTrunkOptions(
            authUsername: env('SIP_USERNAME'),
            authPassword: env('SIP_PASSWORD'),
        ),
    );

    printf('  trunk %s%s', $trunk->getSipTrunkId(), PHP_EOL);

    // "Individual" puts every caller in their own room, named from the prefix
    // plus a random suffix. The alternative, SIPDispatchRuleDirect, sends every
    // caller to one named room.
    $rule = (new SIPDispatchRule())->setDispatchRuleIndividual(
        (new SIPDispatchRuleIndividual())->setRoomPrefix('call-')
    );

    echo 'Creating a dispatch rule...' . PHP_EOL;

    $dispatchRule = $livekit->sip->createSipDispatchRule(
        $rule,
        new CreateSipDispatchRuleOptions(
            name: 'php-sdk-example-' . $suffix,
            trunkIds: [$trunk->getSipTrunkId()],
        ),
    );

    printf('  rule %s%s', $dispatchRule->getSipDispatchRuleId(), PHP_EOL);

    echo 'Outbound trunks now configured:' . PHP_EOL;

    foreach ($livekit->sip->listSipOutboundTrunk() as $info) {
        printf('  %s  %s  %s%s', $info->getSipTrunkId(), $info->getName(), $info->getAddress(), PHP_EOL);
    }

    echo 'Cleaning up...' . PHP_EOL;
    $livekit->sip->deleteSipDispatchRule($dispatchRule->getSipDispatchRuleId());
    $livekit->sip->deleteSipTrunk($trunk->getSipTrunkId());
    echo '  done' . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, sprintf('LiveKit rejected the request: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}
