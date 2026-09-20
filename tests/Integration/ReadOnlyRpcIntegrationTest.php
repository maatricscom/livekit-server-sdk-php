<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Options\ListEgressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\SIPDispatchRuleInfo;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;

/**
 * The list RPCs of the services this suite must not mutate.
 *
 * Starting an egress costs money and needs storage credentials; creating a SIP
 * trunk is configuration someone else owns. Listing is free, and it still proves
 * the parts most likely to be wrong: that the grant this SDK mints for each
 * service is one the server accepts, that the request reaches the right Twirp
 * path, and that a real response decodes into the generated message.
 *
 * An empty list is a pass. These assert the type and the absence of an
 * exception, not the contents of someone's project.
 */
final class ReadOnlyRpcIntegrationTest extends IntegrationTestCase
{
    public function test_egress_can_be_listed(): void
    {
        $egresses = $this->skipIfUnavailable(
            fn () => $this->livekit->egress->listEgress(new ListEgressOptions(active: true)),
            'Egress',
        );

        self::assertContainsOnlyInstancesOf(EgressInfo::class, $egresses);
    }

    public function test_ingress_can_be_listed(): void
    {
        $ingresses = $this->skipIfUnavailable(
            fn () => $this->livekit->ingress->listIngress(new ListIngressOptions()),
            'Ingress',
        );

        self::assertContainsOnlyInstancesOf(IngressInfo::class, $ingresses);
    }

    public function test_sip_inbound_trunks_can_be_listed(): void
    {
        $trunks = $this->skipIfUnavailable(
            fn () => $this->livekit->sip->listSipInboundTrunk(),
            'SIP',
        );

        self::assertContainsOnlyInstancesOf(SIPInboundTrunkInfo::class, $trunks);
    }

    public function test_sip_outbound_trunks_can_be_listed(): void
    {
        $trunks = $this->skipIfUnavailable(
            fn () => $this->livekit->sip->listSipOutboundTrunk(),
            'SIP',
        );

        self::assertContainsOnlyInstancesOf(SIPOutboundTrunkInfo::class, $trunks);
    }

    public function test_sip_dispatch_rules_can_be_listed(): void
    {
        $rules = $this->skipIfUnavailable(
            fn () => $this->livekit->sip->listSipDispatchRule(),
            'SIP',
        );

        self::assertContainsOnlyInstancesOf(SIPDispatchRuleInfo::class, $rules);
    }
}
