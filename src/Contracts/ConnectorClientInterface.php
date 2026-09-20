<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\AcceptWhatsAppCallOptions;
use LiveKit\Options\ConnectTwilioCallOptions;
use LiveKit\Options\ConnectWhatsAppCallOptions;
use LiveKit\Options\DialWhatsAppCallOptions;
use LiveKit\Proto\AcceptWhatsAppCallResponse;
use LiveKit\Proto\ConnectTwilioCallResponse;
use LiveKit\Proto\ConnectWhatsAppCallResponse;
use LiveKit\Proto\DialWhatsAppCallResponse;
use LiveKit\Proto\DisconnectWhatsAppCallResponse;
use LiveKit\Proto\SessionDescription;

/**
 * livekit.Connector: bridging WhatsApp and Twilio calls into LiveKit rooms.
 *
 * LiveKit Cloud only — the open-source server does not implement this service.
 */
interface ConnectorClientInterface
{
    public function dialWhatsAppCall(
        string $whatsappPhoneNumberId,
        string $whatsappToPhoneNumber,
        string $whatsappApiKey,
        string $whatsappCloudApiVersion,
        ?DialWhatsAppCallOptions $opts = null,
    ): DialWhatsAppCallResponse;

    public function acceptWhatsAppCall(
        string $whatsappPhoneNumberId,
        string $whatsappApiKey,
        string $whatsappCloudApiVersion,
        string $whatsappCallId,
        SessionDescription $sdp,
        ?AcceptWhatsAppCallOptions $opts = null,
    ): AcceptWhatsAppCallResponse;

    public function connectWhatsAppCall(
        string $whatsappCallId,
        SessionDescription $sdp,
        ?ConnectWhatsAppCallOptions $opts = null,
    ): ConnectWhatsAppCallResponse;

    public function disconnectWhatsAppCall(
        string $whatsappCallId,
        string $whatsappApiKey,
        ?int $disconnectReason = null,
    ): DisconnectWhatsAppCallResponse;

    public function connectTwilioCall(
        int $twilioCallDirection,
        string $roomName,
        ?ConnectTwilioCallOptions $opts = null,
    ): ConnectTwilioCallResponse;
}
