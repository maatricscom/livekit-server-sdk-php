<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Options\ListSipDispatchRuleOptions;
use LiveKit\Options\ListSipTrunkOptions;
use LiveKit\Options\SipDispatchRuleUpdateOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Options\SipOutboundTrunkUpdateOptions;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleIndividual;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;

/**
 * Trunks and dispatch rules are configuration: creating one costs nothing, rings
 * no telephone and reaches no carrier until a call actually arrives. So unlike
 * egress, the whole of SIP's configuration surface can be driven for real, and
 * this does.
 *
 * The two calls that could reach a telephone are the exception, and they are
 * aimed so that they cannot. createSipParticipant() is pointed at a trunk id that
 * does not exist, so the server fails the lookup before any number is dialled,
 * and transferSipParticipant() at a participant that is not in the room. The
 * numbers used throughout are in the +1 555 0100-0199 range reserved for fiction,
 * so even a bug on either side could not reach a real subscriber. Those two run
 * with failover off: a retryable failure on a dial path would otherwise be
 * replayed across regions, and a test should not take three times as long to
 * discover the same answer.
 *
 * The *Fields() assertions are the point of the lifecycle tests rather than a
 * detail. updateSipInboundTrunk() replaces a trunk wholesale and clears what is
 * omitted, while updateSipInboundTrunkFields() changes only what is passed; the
 * names do not say which is which, and picking the wrong one silently drops
 * authentication credentials or a number. Each partial update here therefore
 * asserts that the fields it did not send survived.
 */
final class SipIntegrationTest extends IntegrationTestCase
{
    /** Reserved for fictional use, so it can never reach a subscriber. */
    private const string FICTIONAL_NUMBER = '+15550100';

    public function test_an_inbound_trunk_can_be_created_read_updated_and_deleted(): void
    {
        $name = $this->scratchName('inbound');

        $trunk = $this->skipIfUnavailable(
            fn (): SIPInboundTrunkInfo => $this->livekit->sip->createSipInboundTrunk(
                $name,
                [self::FICTIONAL_NUMBER],
                new CreateSipInboundTrunkOptions(
                    metadata: '{"from":"php-sdk-integration"}',
                    authUsername: 'probe-user',
                ),
            ),
            'SIP',
        );

        $id = $trunk->getSipTrunkId();
        self::assertNotSame('', $id, 'the server assigns a trunk id');

        $this->cleanUpAfter(
            body: function () use ($id, $name): void {
                $fetched = $this->livekit->sip->getSipInboundTrunk($id);

                self::assertNotNull($fetched, 'a trunk that was just created is fetchable');
                self::assertSame($name, $fetched->getName());
                self::assertSame([self::FICTIONAL_NUMBER], iterator_to_array($fetched->getNumbers(), false));

                self::assertCount(
                    1,
                    $this->livekit->sip->listSipInboundTrunk(new ListSipTrunkOptions(trunkIds: [$id])),
                    'the trunkIds filter reaches the server'
                );

                // Partial: everything not named here has to survive.
                $renamed = $this->livekit->sip->updateSipInboundTrunkFields(
                    $id,
                    new SipInboundTrunkUpdateOptions(name: $name . '-renamed'),
                );

                self::assertSame($name . '-renamed', $renamed->getName());
                self::assertSame('{"from":"php-sdk-integration"}', $renamed->getMetadata(), 'the unsent metadata was not cleared');
                self::assertSame([self::FICTIONAL_NUMBER], iterator_to_array($renamed->getNumbers(), false), 'the unsent numbers were not cleared');
                self::assertSame('probe-user', $renamed->getAuthUsername(), 'the unsent credentials were not cleared');

                // Wholesale: read, change one field, send the message back.
                $current = $this->livekit->sip->getSipInboundTrunk($id);
                self::assertNotNull($current);
                $current->setMetadata('{"from":"php-sdk-integration","replaced":true}');

                self::assertSame(
                    '{"from":"php-sdk-integration","replaced":true}',
                    $this->livekit->sip->updateSipInboundTrunk($id, $current)->getMetadata()
                );
            },
            cleanup: fn () => $this->livekit->sip->deleteSipTrunk($id),
            describe: sprintf('inbound trunk "%s" (%s)', $name, $id),
        );

        self::assertSame([], $this->livekit->sip->listSipInboundTrunk(new ListSipTrunkOptions(trunkIds: [$id])), 'deleteSipTrunk actually removed it');
    }

    public function test_an_outbound_trunk_can_be_created_read_updated_and_deleted(): void
    {
        $name = $this->scratchName('outbound');

        $trunk = $this->skipIfUnavailable(
            fn (): SIPOutboundTrunkInfo => $this->livekit->sip->createSipOutboundTrunk(
                $name,
                'sip.example.com',
                [self::FICTIONAL_NUMBER],
                new CreateSipOutboundTrunkOptions(metadata: '{"from":"php-sdk-integration"}'),
            ),
            'SIP',
        );

        $id = $trunk->getSipTrunkId();
        self::assertNotSame('', $id);
        self::assertSame('sip.example.com', $trunk->getAddress());

        $this->cleanUpAfter(
            body: function () use ($id, $name): void {
                $fetched = $this->livekit->sip->getSipOutboundTrunk($id);

                self::assertNotNull($fetched, 'a trunk that was just created is fetchable');
                self::assertSame($name, $fetched->getName());
                self::assertCount(1, $this->livekit->sip->listSipOutboundTrunk(new ListSipTrunkOptions(trunkIds: [$id])));

                $renamed = $this->livekit->sip->updateSipOutboundTrunkFields(
                    $id,
                    new SipOutboundTrunkUpdateOptions(name: $name . '-renamed'),
                );

                self::assertSame($name . '-renamed', $renamed->getName());
                self::assertSame('sip.example.com', $renamed->getAddress(), 'the unsent address was not cleared');

                $current = $this->livekit->sip->getSipOutboundTrunk($id);
                self::assertNotNull($current);
                $current->setMetadata('{"replaced":true}');

                self::assertSame('{"replaced":true}', $this->livekit->sip->updateSipOutboundTrunk($id, $current)->getMetadata());
            },
            cleanup: fn () => $this->livekit->sip->deleteSipTrunk($id),
            describe: sprintf('outbound trunk "%s" (%s)', $name, $id),
        );

        self::assertSame([], $this->livekit->sip->listSipOutboundTrunk(new ListSipTrunkOptions(trunkIds: [$id])), 'deleteSipTrunk actually removed it');
    }

    public function test_a_dispatch_rule_can_be_created_listed_updated_and_deleted(): void
    {
        $name = $this->scratchName('rule');

        $individual = new SIPDispatchRuleIndividual();
        $individual->setRoomPrefix($this->scratchName('call'));

        $rule = new SIPDispatchRule();
        $rule->setDispatchRuleIndividual($individual);

        $info = $this->skipIfUnavailable(
            fn () => $this->livekit->sip->createSipDispatchRule(
                $rule,
                new CreateSipDispatchRuleOptions(name: $name, metadata: '{"from":"php-sdk-integration"}'),
            ),
            'SIP',
        );

        $id = $info->getSipDispatchRuleId();
        self::assertNotSame('', $id, 'the server assigns a rule id');

        $this->cleanUpAfter(
            body: function () use ($id, $name): void {
                self::assertCount(
                    1,
                    $this->livekit->sip->listSipDispatchRule(new ListSipDispatchRuleOptions(dispatchRuleIds: [$id])),
                    'the dispatchRuleIds filter reaches the server'
                );

                $renamed = $this->livekit->sip->updateSipDispatchRuleFields(
                    $id,
                    new SipDispatchRuleUpdateOptions(name: $name . '-renamed'),
                );

                self::assertSame($name . '-renamed', $renamed->getName());
                self::assertSame('{"from":"php-sdk-integration"}', $renamed->getMetadata(), 'the unsent metadata was not cleared');

                $current = $this->livekit->sip->listSipDispatchRule(new ListSipDispatchRuleOptions(dispatchRuleIds: [$id]))[0];
                $current->setMetadata('{"replaced":true}');

                self::assertSame('{"replaced":true}', $this->livekit->sip->updateSipDispatchRule($id, $current)->getMetadata());
            },
            cleanup: fn () => $this->livekit->sip->deleteSipDispatchRule($id),
            describe: sprintf('dispatch rule "%s" (%s)', $name, $id),
        );

        self::assertSame([], $this->livekit->sip->listSipDispatchRule(new ListSipDispatchRuleOptions(dispatchRuleIds: [$id])), 'deleteSipDispatchRule actually removed it');
    }

    public function test_the_deprecated_trunk_list_still_answers(): void
    {
        $this->skipIfUnavailable(fn (): array => $this->livekit->sip->listSipTrunk(), 'SIP');
        self::addToAssertionCount(1);
    }

    /**
     * The one test that could have rung a telephone, arranged so that it cannot:
     * the trunk id does not exist, so the server fails the lookup first.
     */
    public function test_dialling_through_a_trunk_that_does_not_exist_places_no_call(): void
    {
        $room = $this->scratchName('dial');

        $this->cleanUpAfter(
            body: function () use ($room): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                $client = $this->noFailoverClient()->sip;

                try {
                    $this->skipIfUnavailable(
                        fn () => $client->createSipParticipant('ST_does_not_exist', self::FICTIONAL_NUMBER, $room),
                        'SIP',
                    );
                } catch (TwirpException $e) {
                    self::assertSame(TwirpErrorCode::NOT_FOUND, $e->getTwirpCode(), $e->getMessage());

                    return;
                }

                self::fail('a dial through a trunk that does not exist should not have succeeded');
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($room),
            describe: sprintf('room "%s"', $room),
        );
    }

    public function test_transferring_a_participant_that_is_not_in_the_room_is_refused(): void
    {
        $room = $this->scratchName('transfer');

        $this->cleanUpAfter(
            body: function () use ($room): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                try {
                    $this->skipIfUnavailable(
                        fn () => $this->noFailoverClient()->sip->transferSipParticipant($room, 'absent-identity', 'tel:' . self::FICTIONAL_NUMBER),
                        'SIP',
                    );
                } catch (TwirpException $e) {
                    self::assertSame(TwirpErrorCode::NOT_FOUND, $e->getTwirpCode(), $e->getMessage());

                    return;
                }

                self::fail('transferring an absent participant should not have succeeded');
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($room),
            describe: sprintf('room "%s"', $room),
        );
    }
}
