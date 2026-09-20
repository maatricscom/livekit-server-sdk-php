<?php

declare(strict_types=1);

namespace LiveKit\Enums;

/**
 * The webhook event names LiveKit emits, copied from webhook/consts.go in
 * livekit/protocol.
 *
 * WebhookEvent::getEvent() returns a plain string; use tryFrom() to resolve it,
 * because a newer LiveKit server may send an event this enum does not know yet.
 */
enum WebhookEventType: string
{
    case RoomStarted = 'room_started';
    case RoomFinished = 'room_finished';
    case ParticipantJoined = 'participant_joined';
    case ParticipantLeft = 'participant_left';
    case ParticipantConnectionAborted = 'participant_connection_aborted';
    case TrackPublished = 'track_published';
    case TrackUnpublished = 'track_unpublished';
    case EgressStarted = 'egress_started';
    case EgressUpdated = 'egress_updated';
    case EgressEnded = 'egress_ended';
    case IngressStarted = 'ingress_started';
    case IngressEnded = 'ingress_ended';
    case AgentJobStarted = 'agent_job_started';
    case AgentJobEnded = 'agent_job_ended';
}
