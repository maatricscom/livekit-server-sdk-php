<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer;

use LiveKit\Enums\WireFormat;
use LiveKit\LiveKitAPI;
use LiveKit\Options\AcceptWhatsAppCallOptions;
use LiveKit\Options\ClientOptions;
use LiveKit\Options\ConnectTwilioCallOptions;
use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipParticipantOptions;
use LiveKit\Options\DialWhatsAppCallOptions;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\SipDispatchRuleUpdateOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Options\SipOutboundTrunkUpdateOptions;
use LiveKit\Options\TransferSipParticipantOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Options\UpdateParticipantOptions;
use LiveKit\Proto\ConnectTwilioCallRequest\TwilioCallDirection;
use LiveKit\Proto\DirectFileOutput;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\SessionDescription;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleDirect;
use LiveKit\Proto\SIPDispatchRuleInfo;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Proto\StartEgressRequest;
use LiveKit\Tests\MockServer\Support\MockServerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Calls every RPC this SDK exposes against the mock, once per wire format.
 *
 * RpcCoverageTest holds this list to that promise: it fails if a service client
 * grows a method with no entry here.
 *
 * What this proves, and what it does not, is worth being precise about.
 *
 * It proves **authorization**: the mock enforces the same per-RPC permission
 * table as the real server, so a method whose VideoGrant is too narrow fails
 * here with permission_denied. That is the bug which is otherwise invisible
 * until a user hits it in production, and it is covered for all 47 RPCs.
 * Verified by mutation: weakening deleteRoom's grant from roomCreate to roomList
 * fails this test.
 *
 * It also proves the **response** decodes -- the bytes are LiveKit's, produced by
 * their encoder, and our generated classes have to read them in both formats.
 *
 * It does NOT prove our **request** encoding. The mock decodes with
 * `_ = proto.Unmarshal(body, req)` and discards the error, so a malformed body
 * still returns 200 and this sweep stays green. EchoRoundTripTest is what covers
 * that, by asserting on values the server could only send back if it had parsed
 * them.
 *
 * Responses are not asserted in detail on purpose: the mock echoes and
 * fabricates, so its payloads say nothing about what a real deployment returns.
 */
final class RpcSweepTest extends MockServerTestCase
{
    /** @return iterable<string, array{callable(LiveKitAPI): mixed, 1?: bool}> */
    public static function rpcs(): iterable
    {
        // -- RoomService ---------------------------------------------------
        yield 'room.createRoom' => [static fn (LiveKitAPI $a): mixed => $a->room->createRoom(new CreateRoomOptions(name: 'sweep', emptyTimeout: 30))];
        yield 'room.listRooms' => [static fn (LiveKitAPI $a): mixed => $a->room->listRooms()];
        yield 'room.deleteRoom' => [static fn (LiveKitAPI $a): mixed => $a->room->deleteRoom('sweep')];
        yield 'room.listParticipants' => [static fn (LiveKitAPI $a): mixed => $a->room->listParticipants('sweep')];
        yield 'room.getParticipant' => [static fn (LiveKitAPI $a): mixed => $a->room->getParticipant('sweep', 'alice')];
        yield 'room.removeParticipant' => [static fn (LiveKitAPI $a): mixed => $a->room->removeParticipant('sweep', 'alice')];
        yield 'room.mutePublishedTrack' => [static fn (LiveKitAPI $a): mixed => $a->room->mutePublishedTrack('sweep', 'alice', 'TR_abc', true)];
        yield 'room.updateParticipant' => [static fn (LiveKitAPI $a): mixed => $a->room->updateParticipant('sweep', 'alice', new UpdateParticipantOptions(metadata: 'm'))];
        yield 'room.updateSubscriptions' => [static fn (LiveKitAPI $a): mixed => $a->room->updateSubscriptions('sweep', 'alice', ['TR_abc'], true)];
        yield 'room.sendData' => [static fn (LiveKitAPI $a): mixed => $a->room->sendData('sweep', 'payload')];
        yield 'room.updateRoomMetadata' => [static fn (LiveKitAPI $a): mixed => $a->room->updateRoomMetadata('sweep', 'meta')];
        yield 'room.forwardParticipant' => [static fn (LiveKitAPI $a): mixed => $a->room->forwardParticipant('sweep', 'alice', 'other')];
        yield 'room.moveParticipant' => [static fn (LiveKitAPI $a): mixed => $a->room->moveParticipant('sweep', 'alice', 'other')];
        yield 'room.performRpc' => [static fn (LiveKitAPI $a): mixed => $a->room->performRpc('sweep', 'alice', 'method', 'payload')];

        // -- Egress --------------------------------------------------------
        yield 'egress.startRoomCompositeEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startRoomCompositeEgress('sweep', self::encodedOutputs())];
        yield 'egress.startWebEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startWebEgress('https://example.com', self::encodedOutputs())];
        yield 'egress.startParticipantEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startParticipantEgress('sweep', 'alice', self::encodedOutputs())];
        yield 'egress.startTrackCompositeEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startTrackCompositeEgress('sweep', self::encodedOutputs())];
        yield 'egress.startTrackEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startTrackEgress('sweep', new DirectFileOutput()->setFilepath('out.mp4'), 'TR_abc')];
        yield 'egress.startEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startEgress(
            new StartEgressRequest()->setRoomName('sweep')
        )];
        yield 'egress.updateLayout' => [static fn (LiveKitAPI $a): mixed => $a->egress->updateLayout('EG_abc', 'speaker')];
        yield 'egress.updateStream' => [static fn (LiveKitAPI $a): mixed => $a->egress->updateStream('EG_abc', ['rtmp://example.com/live'])];
        yield 'egress.listEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->listEgress()];
        yield 'egress.stopEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->stopEgress('EG_abc')];

        // -- Ingress -------------------------------------------------------
        yield 'ingress.createIngress' => [static fn (LiveKitAPI $a): mixed => $a->ingress->createIngress(new CreateIngressOptions(name: 'sweep', roomName: 'sweep'))];
        yield 'ingress.updateIngress' => [static fn (LiveKitAPI $a): mixed => $a->ingress->updateIngress('IN_abc', new UpdateIngressOptions(name: 'renamed'))];
        yield 'ingress.listIngress' => [static fn (LiveKitAPI $a): mixed => $a->ingress->listIngress()];
        yield 'ingress.deleteIngress' => [static fn (LiveKitAPI $a): mixed => $a->ingress->deleteIngress('IN_abc')];

        // -- SIP -----------------------------------------------------------
        yield 'sip.createSipInboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->createSipInboundTrunk('sweep', ['+15551234567'])];
        yield 'sip.createSipOutboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->createSipOutboundTrunk('sweep', 'sip.example.com', ['+15551234567'])];
        yield 'sip.updateSipInboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipInboundTrunk('ST_abc', new SIPInboundTrunkInfo()->setName('renamed'))];
        yield 'sip.updateSipInboundTrunkFields' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipInboundTrunkFields('ST_abc', new SipInboundTrunkUpdateOptions(name: 'renamed'))];
        yield 'sip.updateSipOutboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipOutboundTrunk('ST_abc', new SIPOutboundTrunkInfo()->setName('renamed'))];
        yield 'sip.updateSipOutboundTrunkFields' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipOutboundTrunkFields('ST_abc', new SipOutboundTrunkUpdateOptions(name: 'renamed'))];
        // Nullable by contract: the response wraps the trunk in an optional field,
        // and the mock only populates scalars, so the wrapper comes back unset.
        yield 'sip.getSipInboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->getSipInboundTrunk('ST_abc'), true];
        yield 'sip.getSipOutboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->getSipOutboundTrunk('ST_abc'), true];
        yield 'sip.listSipInboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->listSipInboundTrunk()];
        yield 'sip.listSipOutboundTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->listSipOutboundTrunk()];
        // Deprecated upstream (the rpc carries option deprecated = true) but still
        // served, and still ours to keep working.
        yield 'sip.listSipTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->listSipTrunk()];
        yield 'sip.deleteSipTrunk' => [static fn (LiveKitAPI $a): mixed => $a->sip->deleteSipTrunk('ST_abc')];
        yield 'sip.createSipDispatchRule' => [static fn (LiveKitAPI $a): mixed => $a->sip->createSipDispatchRule(
            new SIPDispatchRule()->setDispatchRuleDirect(new SIPDispatchRuleDirect()->setRoomName('sweep')),
            new CreateSipDispatchRuleOptions(name: 'sweep')
        )];
        yield 'sip.updateSipDispatchRule' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipDispatchRule('SDR_abc', new SIPDispatchRuleInfo()->setName('renamed'))];
        yield 'sip.updateSipDispatchRuleFields' => [static fn (LiveKitAPI $a): mixed => $a->sip->updateSipDispatchRuleFields('SDR_abc', new SipDispatchRuleUpdateOptions(name: 'renamed'))];
        yield 'sip.listSipDispatchRule' => [static fn (LiveKitAPI $a): mixed => $a->sip->listSipDispatchRule()];
        yield 'sip.deleteSipDispatchRule' => [static fn (LiveKitAPI $a): mixed => $a->sip->deleteSipDispatchRule('SDR_abc')];
        yield 'sip.createSipParticipant' => [static fn (LiveKitAPI $a): mixed => $a->sip->createSipParticipant(
            'ST_abc',
            '+15551234567',
            'sweep',
            new CreateSipParticipantOptions(participantIdentity: 'caller')
        )];
        yield 'sip.transferSipParticipant' => [static fn (LiveKitAPI $a): mixed => $a->sip->transferSipParticipant(
            'sweep',
            'caller',
            'tel:+15557654321',
            new TransferSipParticipantOptions(playDialtone: true)
        )];

        // -- Connector -----------------------------------------------------
        yield 'connector.dialWhatsAppCall' => [static fn (LiveKitAPI $a): mixed => $a->connector->dialWhatsAppCall(
            'PN_sweep',
            '+15551234567',
            'meta-api-key',
            '23.0',
            new DialWhatsAppCallOptions(roomName: 'sweep')
        )];
        yield 'connector.acceptWhatsAppCall' => [static fn (LiveKitAPI $a): mixed => $a->connector->acceptWhatsAppCall(
            'PN_sweep',
            'meta-api-key',
            '23.0',
            'WACID_sweep',
            self::sdp(),
            new AcceptWhatsAppCallOptions(roomName: 'sweep')
        )];
        yield 'connector.connectWhatsAppCall' => [static fn (LiveKitAPI $a): mixed => $a->connector->connectWhatsAppCall('WACID_sweep', self::sdp())];
        yield 'connector.disconnectWhatsAppCall' => [static fn (LiveKitAPI $a): mixed => $a->connector->disconnectWhatsAppCall('WACID_sweep', 'meta-api-key')];
        yield 'connector.connectTwilioCall' => [static fn (LiveKitAPI $a): mixed => $a->connector->connectTwilioCall(
            TwilioCallDirection::TWILIO_CALL_DIRECTION_INBOUND,
            'sweep',
            new ConnectTwilioCallOptions(participantIdentity: 'twilio-caller')
        )];

        // -- AgentDispatch -------------------------------------------------
        yield 'agentDispatch.createDispatch' => [static fn (LiveKitAPI $a): mixed => $a->agentDispatch->createDispatch('sweep', 'agent', new CreateDispatchOptions(metadata: 'm'))];
        yield 'agentDispatch.deleteDispatch' => [static fn (LiveKitAPI $a): mixed => $a->agentDispatch->deleteDispatch('AD_abc', 'sweep')];
        // Nullable here for the same reason the SIP trunk getters are: the mock does
        // not populate AgentDispatchService responses, so the list comes back empty.
        yield 'agentDispatch.getDispatch' => [static fn (LiveKitAPI $a): mixed => $a->agentDispatch->getDispatch('AD_abc', 'sweep'), true];
        yield 'agentDispatch.listDispatch' => [static fn (LiveKitAPI $a): mixed => $a->agentDispatch->listDispatch('sweep')];
    }

    private static function sdp(): SessionDescription
    {
        return new SessionDescription()->setType('offer')->setSdp('v=0');
    }

    private static function encodedOutputs(): EncodedOutputs
    {
        return new EncodedOutputs(file: new EncodedFileOutput()->setFilepath('out.mp4'));
    }

    /** @param callable(LiveKitAPI): mixed $call */
    #[DataProvider('rpcs')]
    public function test_rpc_is_accepted_over_binary_protobuf(callable $call, bool $mayBeNull = false): void
    {
        $this->assertAccepted($call, WireFormat::Protobuf, $mayBeNull);
    }

    /** @param callable(LiveKitAPI): mixed $call */
    #[DataProvider('rpcs')]
    public function test_rpc_is_accepted_over_json(callable $call, bool $mayBeNull = false): void
    {
        $this->assertAccepted($call, WireFormat::Json, $mayBeNull);
    }

    /** @param callable(LiveKitAPI): mixed $call */
    private function assertAccepted(callable $call, WireFormat $format, bool $mayBeNull): void
    {
        // delayMs:0 cancels the mock's deliberate ~11s wait on the SIP dialing
        // methods, which model a ringing phone. The wait is the subject of its
        // own test; here it would just make the sweep take minutes.
        $api = $this->api(['delayMs' => 0], new ClientOptions(wireFormat: $format));

        $result = $call($api);

        if (!$mayBeNull) {
            self::assertNotNull($result, 'The RPC returned null rather than a decoded response.');
        }

        self::assertSame(1, $this->http->attempts(), 'Expected exactly one HTTP request.');
        self::assertSame(
            $format->contentType(),
            $this->http->requests()[0]->getHeaderLine('Content-Type'),
            'The request did not go out in the wire format under test.'
        );
    }
}
