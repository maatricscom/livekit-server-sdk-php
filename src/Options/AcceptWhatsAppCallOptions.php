<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\RoomAgentDispatch;

/**
 * Options for ConnectorClient::acceptWhatsAppCall().
 *
 * Maps onto livekit.AcceptWhatsAppCallRequest.
 */
final readonly class AcceptWhatsAppCallOptions
{
    /**
     * @param string|null                $bizOpaqueCallbackData an arbitrary string echoed back by Meta, for tracing (proto: whatsapp_biz_opaque_callback_data)
     * @param string|null                $roomName              the room to connect the caller to; LiveKit picks one when null
     * @param list<RoomAgentDispatch>    $agents                agents to dispatch into the room
     * @param array<string, string>|null $participantAttributes attached to the created participant
     * @param string|null                $destinationCountry    ISO 3166-1 alpha-2 country the call terminates in
     * @param int|null                   $ringingTimeout        seconds the caller has to be connected in
     * @param bool|null                  $waitUntilAnswered     hold the request open until the caller joins the room
     * @param int|null                   $timeout               HTTP request timeout in seconds. NOT a proto field: it is a
     *                                                          transport-level knob. Ignored unless $waitUntilAnswered is
     *                                                          set, and raised to the ring window plus a margin when it is.
     *                                                          See LiveKit\Http\DialTimeout.
     */
    public function __construct(
        public ?string $bizOpaqueCallbackData = null,
        public ?string $roomName = null,
        public array $agents = [],
        public ?string $participantIdentity = null,
        public ?string $participantName = null,
        public ?string $participantMetadata = null,
        public ?array $participantAttributes = null,
        public ?string $destinationCountry = null,
        public ?int $ringingTimeout = null,
        public ?bool $waitUntilAnswered = null,
        public ?int $timeout = null,
    ) {
    }
}
