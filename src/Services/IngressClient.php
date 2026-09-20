<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Contracts\IngressClientInterface;
use LiveKit\Enums\ProtoEnum;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\CreateIngressRequest;
use LiveKit\Proto\DeleteIngressRequest;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\IngressInput;
use LiveKit\Proto\ListIngressRequest;
use LiveKit\Proto\ListIngressResponse;
use LiveKit\Proto\TokenPagination;
use LiveKit\Proto\UpdateIngressRequest;

final class IngressClient extends ServiceBase implements IngressClientInterface
{
    private const SERVICE = 'Ingress';

    public function createIngress(CreateIngressOptions $options): IngressInfo
    {
        $request = new CreateIngressRequest();
        $request->setInputType(ProtoEnum::check(IngressInput::class, $options->inputType, 'inputType'));

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

    public function updateIngress(string $ingressId, UpdateIngressOptions $options): IngressInfo
    {
        $request = new UpdateIngressRequest();
        $request->setIngressId($ingressId);

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
            'UpdateIngress',
            $request,
            IngressInfo::class,
            $this->authHeader(new VideoGrant(ingressAdmin: true)),
        );
    }

    /** @return list<IngressInfo> */
    public function listIngress(?ListIngressOptions $options = null): array
    {
        $request = new ListIngressRequest();

        if ($options !== null) {
            if ($options->roomName !== null) {
                $request->setRoomName($options->roomName);
            }

            if ($options->ingressId !== null) {
                $request->setIngressId($options->ingressId);
            }

            if ($options->pageToken !== null) {
                $pagination = new TokenPagination();
                $pagination->setToken($options->pageToken);
                $request->setPageToken($pagination);
            }
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListIngress',
            $request,
            ListIngressResponse::class,
            $this->authHeader(new VideoGrant(ingressAdmin: true)),
        );

        /** @var list<IngressInfo> $items */
        $items = [];

        foreach ($response->getItems() as $item) {
            $items[] = $item;
        }

        return $items;
    }

    public function deleteIngress(string $ingressId): IngressInfo
    {
        $request = new DeleteIngressRequest();
        $request->setIngressId($ingressId);

        return $this->rpc(
            self::SERVICE,
            'DeleteIngress',
            $request,
            IngressInfo::class,
            $this->authHeader(new VideoGrant(ingressAdmin: true)),
        );
    }
}
