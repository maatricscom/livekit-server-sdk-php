<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Duration;
use LiveKit\Contracts\ConnectorClientInterface;
use LiveKit\Grants\VideoGrant;
use LiveKit\Http\DialTimeout;
use LiveKit\Options\AcceptWhatsAppCallOptions;
use LiveKit\Options\ConnectTwilioCallOptions;
use LiveKit\Options\ConnectWhatsAppCallOptions;
use LiveKit\Options\DialWhatsAppCallOptions;
use LiveKit\Proto\AcceptWhatsAppCallRequest;
use LiveKit\Proto\AcceptWhatsAppCallResponse;
use LiveKit\Proto\ConnectTwilioCallRequest;
use LiveKit\Proto\ConnectTwilioCallResponse;
use LiveKit\Proto\ConnectWhatsAppCallRequest;
use LiveKit\Proto\ConnectWhatsAppCallResponse;
use LiveKit\Proto\DialWhatsAppCallRequest;
use LiveKit\Proto\DialWhatsAppCallResponse;
use LiveKit\Proto\DisconnectWhatsAppCallRequest;
use LiveKit\Proto\DisconnectWhatsAppCallResponse;
use LiveKit\Proto\SessionDescription;

/**
 * Bridges calls from WhatsApp and Twilio into LiveKit rooms.
 *
 * A LiveKit Cloud service: livekit.Connector has no implementation in the
 * open-source server, so these five RPCs only answer on a Cloud project.
 *
 * The WhatsApp methods come in two shapes, matching the two directions a call can
 * go. dialWhatsAppCall() places an outbound call, and the business supplies its
 * own Meta credentials on every request -- LiveKit does not store them.
 * acceptWhatsAppCall() answers an inbound one, taking the SDP that arrived on
 * Meta's webhook; connectWhatsAppCall() completes the handshake for a call the
 * business started. The Twilio side is a single method returning the WebSocket URL
 * to point a Twilio media stream at.
 *
 * Every method needs roomCreate, since each one ends in a participant joining a
 * room that may not exist yet.
 */
final class ConnectorClient extends ServiceBase implements ConnectorClientInterface
{
    private const SERVICE = 'Connector';

    /**
     * Places an outbound WhatsApp call.
     *
     * @param string $whatsappPhoneNumberId   the WhatsApp phone number id placing the call
     * @param string $whatsappToPhoneNumber   the number to call
     * @param string $whatsappApiKey          the calling business's Meta API key
     * @param string $whatsappCloudApiVersion WhatsApp Cloud API version, e.g. '23.0'
     */
    public function dialWhatsAppCall(
        string $whatsappPhoneNumberId,
        string $whatsappToPhoneNumber,
        string $whatsappApiKey,
        string $whatsappCloudApiVersion,
        ?DialWhatsAppCallOptions $opts = null,
    ): DialWhatsAppCallResponse {
        $request = new DialWhatsAppCallRequest();
        $request->setWhatsappPhoneNumberId($whatsappPhoneNumberId);
        $request->setWhatsappToPhoneNumber($whatsappToPhoneNumber);
        $request->setWhatsappApiKey($whatsappApiKey);
        $request->setWhatsappCloudApiVersion($whatsappCloudApiVersion);

        if ($opts !== null) {
            if ($opts->bizOpaqueCallbackData !== null) {
                $request->setWhatsappBizOpaqueCallbackData($opts->bizOpaqueCallbackData);
            }
            if ($opts->roomName !== null) {
                $request->setRoomName($opts->roomName);
            }
            if ($opts->agents !== []) {
                $request->setAgents($opts->agents);
            }
            if ($opts->participantIdentity !== null) {
                $request->setParticipantIdentity($opts->participantIdentity);
            }
            if ($opts->participantName !== null) {
                $request->setParticipantName($opts->participantName);
            }
            if ($opts->participantMetadata !== null) {
                $request->setParticipantMetadata($opts->participantMetadata);
            }
            if ($opts->participantAttributes !== null) {
                $request->setParticipantAttributes($opts->participantAttributes);
            }
            if ($opts->destinationCountry !== null) {
                $request->setDestinationCountry($opts->destinationCountry);
            }
            if ($opts->ringingTimeout !== null) {
                $request->setRingingTimeout((new Duration())->setSeconds($opts->ringingTimeout));
            }
        }

        return $this->rpc(
            self::SERVICE,
            'DialWhatsAppCall',
            $request,
            DialWhatsAppCallResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );
    }

    /**
     * Answers an inbound WhatsApp call.
     *
     * @param string             $whatsappCallId Meta's id for the call
     * @param SessionDescription $sdp            the offer that arrived on Meta's webhook
     */
    public function acceptWhatsAppCall(
        string $whatsappPhoneNumberId,
        string $whatsappApiKey,
        string $whatsappCloudApiVersion,
        string $whatsappCallId,
        SessionDescription $sdp,
        ?AcceptWhatsAppCallOptions $opts = null,
    ): AcceptWhatsAppCallResponse {
        $request = new AcceptWhatsAppCallRequest();
        $request->setWhatsappPhoneNumberId($whatsappPhoneNumberId);
        $request->setWhatsappApiKey($whatsappApiKey);
        $request->setWhatsappCloudApiVersion($whatsappCloudApiVersion);
        $request->setWhatsappCallId($whatsappCallId);
        $request->setSdp($sdp);

        $ringingTimeout = $opts?->ringingTimeout;
        $requestTimeout = $opts?->timeout;

        if ($opts !== null) {
            if ($opts->bizOpaqueCallbackData !== null) {
                $request->setWhatsappBizOpaqueCallbackData($opts->bizOpaqueCallbackData);
            }
            if ($opts->roomName !== null) {
                $request->setRoomName($opts->roomName);
            }
            if ($opts->agents !== []) {
                $request->setAgents($opts->agents);
            }
            if ($opts->participantIdentity !== null) {
                $request->setParticipantIdentity($opts->participantIdentity);
            }
            if ($opts->participantName !== null) {
                $request->setParticipantName($opts->participantName);
            }
            if ($opts->participantMetadata !== null) {
                $request->setParticipantMetadata($opts->participantMetadata);
            }
            if ($opts->participantAttributes !== null) {
                $request->setParticipantAttributes($opts->participantAttributes);
            }
            if ($opts->destinationCountry !== null) {
                $request->setDestinationCountry($opts->destinationCountry);
            }
            if ($opts->waitUntilAnswered !== null) {
                $request->setWaitUntilAnswered($opts->waitUntilAnswered);
            }

            if ($opts->waitUntilAnswered === true) {
                // The request now blocks while the call rings, so pin the window
                // explicitly and derive the request timeout from it rather than
                // letting the server default decide how long we are willing to wait.
                //
                // The Node SDK uses the bare ring window here, with no margin, even
                // though it adds one for the SIP equivalent. A timeout equal to the
                // ring window can abort at the moment the call is answered, so this
                // uses the same floor as everything else that rings.
                $ringingTimeout ??= DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS;
                $requestTimeout = DialTimeout::requestTimeout($opts->timeout, $ringingTimeout);
            }

            if ($ringingTimeout !== null) {
                $request->setRingingTimeout((new Duration())->setSeconds($ringingTimeout));
            }
        }

        return $this->rpc(
            self::SERVICE,
            'AcceptWhatsAppCall',
            $request,
            AcceptWhatsAppCallResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
            $requestTimeout,
        );
    }

    /**
     * Completes the handshake for a business-initiated WhatsApp call.
     *
     * @param string             $whatsappCallId Meta's id for the call
     * @param SessionDescription $sdp            the answer from Meta
     */
    public function connectWhatsAppCall(
        string $whatsappCallId,
        SessionDescription $sdp,
        ?ConnectWhatsAppCallOptions $opts = null,
    ): ConnectWhatsAppCallResponse {
        $request = new ConnectWhatsAppCallRequest();
        $request->setWhatsappCallId($whatsappCallId);
        $request->setSdp($sdp);

        $requestTimeout = $opts?->timeout;

        if ($opts?->waitUntilAnswered !== null) {
            $request->setWaitUntilAnswered($opts->waitUntilAnswered);

            if ($opts->waitUntilAnswered) {
                // No ringing_timeout field on this request, so the ring window is
                // whatever the server uses; the floor assumes the documented default.
                $requestTimeout = DialTimeout::requestTimeout($opts->timeout, null);
            }
        }

        return $this->rpc(
            self::SERVICE,
            'ConnectWhatsAppCall',
            $request,
            ConnectWhatsAppCallResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
            $requestTimeout,
        );
    }

    /**
     * Ends an active WhatsApp call.
     *
     * @param string   $whatsappApiKey   required when $disconnectReason is BUSINESS_INITIATED
     * @param int|null $disconnectReason a LiveKit\Proto\DisconnectWhatsAppCallRequest\DisconnectReason
     *                                   constant; the server defaults to BUSINESS_INITIATED
     */
    public function disconnectWhatsAppCall(
        string $whatsappCallId,
        string $whatsappApiKey,
        ?int $disconnectReason = null,
    ): DisconnectWhatsAppCallResponse {
        $request = new DisconnectWhatsAppCallRequest();
        $request->setWhatsappCallId($whatsappCallId);
        $request->setWhatsappApiKey($whatsappApiKey);

        if ($disconnectReason !== null) {
            $request->setDisconnectReason($disconnectReason);
        }

        return $this->rpc(
            self::SERVICE,
            'DisconnectWhatsAppCall',
            $request,
            DisconnectWhatsAppCallResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );
    }

    /**
     * Connects a Twilio call to a room.
     *
     * @param int $twilioCallDirection a LiveKit\Proto\ConnectTwilioCallRequest\TwilioCallDirection constant
     *
     * @return ConnectTwilioCallResponse carries the WebSocket URL to point Twilio's media stream at
     */
    public function connectTwilioCall(
        int $twilioCallDirection,
        string $roomName,
        ?ConnectTwilioCallOptions $opts = null,
    ): ConnectTwilioCallResponse {
        $request = new ConnectTwilioCallRequest();
        $request->setTwilioCallDirection($twilioCallDirection);
        $request->setRoomName($roomName);

        if ($opts !== null) {
            if ($opts->agents !== []) {
                $request->setAgents($opts->agents);
            }
            if ($opts->participantIdentity !== null) {
                $request->setParticipantIdentity($opts->participantIdentity);
            }
            if ($opts->participantName !== null) {
                $request->setParticipantName($opts->participantName);
            }
            if ($opts->participantMetadata !== null) {
                $request->setParticipantMetadata($opts->participantMetadata);
            }
            if ($opts->participantAttributes !== null) {
                $request->setParticipantAttributes($opts->participantAttributes);
            }
            if ($opts->destinationCountry !== null) {
                $request->setDestinationCountry($opts->destinationCountry);
            }
        }

        return $this->rpc(
            self::SERVICE,
            'ConnectTwilioCall',
            $request,
            ConnectTwilioCallResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );
    }
}
