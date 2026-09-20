<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\RoomAgentDispatch;

/**
 * Options for ConnectorClient::dialWhatsAppCall().
 *
 * Maps onto livekit.DialWhatsAppCallRequest. The four credentials the call cannot
 * be placed without — the WhatsApp phone number id, the number to call, the
 * business API key and the Cloud API version — are arguments of the method rather
 * than fields here.
 */
final readonly class DialWhatsAppCallOptions
{
    /**
     * @param string|null                $bizOpaqueCallbackData an arbitrary string echoed back by Meta, for tracing (proto: whatsapp_biz_opaque_callback_data)
     * @param string|null                $roomName              the room to connect the caller to; LiveKit picks one when null
     * @param list<RoomAgentDispatch>    $agents                agents to dispatch into the room
     * @param array<string, string>|null $participantAttributes attached to the created participant
     * @param string|null                $destinationCountry    ISO 3166-1 alpha-2 country the call terminates in
     * @param int|null                   $ringingTimeout        seconds the callee has to answer
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
    ) {
    }
}
