<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Proto\CreateIngressRequest;
use LiveKit\Proto\IngressInfo;

final class IngressClient extends ServiceBase
{
    private const SERVICE = 'Ingress';

    public function createIngress(CreateIngressOptions $options): IngressInfo
    {
        $request = new CreateIngressRequest();
        $request->setInputType($options->inputType);

        if ($options->name !== null) {
            $request->setName($options->name);
        }

        if ($options->roomName !== null) {
            $request->setRoomName($options->roomName);
        }

        if ($options->participantIdentity !== null) {
            $request->setParticipantIdentity($options->participantIdentity);
        }

        if ($options->participantName !== null) {
            $request->setParticipantName($options->participantName);
        }

        if ($options->participantMetadata !== null) {
            $request->setParticipantMetadata($options->participantMetadata);
        }

        if ($options->url !== null) {
            $request->setUrl($options->url);
        }

        if ($options->enableTranscoding !== null) {
            $request->setEnableTranscoding($options->enableTranscoding);
        }

        if ($options->enabled !== null) {
            $request->setEnabled($options->enabled);
        }

        if ($options->audio !== null) {
            $request->setAudio($options->audio);
        }

        if ($options->video !== null) {
            $request->setVideo($options->video);
        }

        return $this->rpc(
            self::SERVICE,
            'CreateIngress',
            $request,
            IngressInfo::class,
            $this->authHeader(new VideoGrant(ingressAdmin: true)),
        );
    }
}
