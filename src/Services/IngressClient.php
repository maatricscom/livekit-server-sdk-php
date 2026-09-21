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
    private const string SERVICE = 'Ingress';

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
        /** @var list<IngressInfo> $items */
        $items = [];

        // See EgressClient::listEgress() for why the cursor is followed here
        // rather than returned. A LiveKit Cloud project caps ingress objects well
        // below any page size -- measured at 40 with no cursor, and refused at
        // about 50 -- so this loop is expected to run once. It is here because
        // the RPC can paginate, not because this one is known to.
        $token = $options->pageToken ?? '';
        $seen = [];

        do {
            $request = new ListIngressRequest();

            if ($options?->roomName !== null) {
                $request->setRoomName($options->roomName);
            }

            if ($options?->ingressId !== null) {
                $request->setIngressId($options->ingressId);
            }

            if ($token !== '') {
                $pagination = new TokenPagination();
                $pagination->setToken($token);
                $request->setPageToken($pagination);
            }

            $response = $this->rpc(
                self::SERVICE,
                'ListIngress',
                $request,
                ListIngressResponse::class,
                $this->authHeader(new VideoGrant(ingressAdmin: true)),
            );

            foreach ($response->getItems() as $item) {
                $items[] = $item;
            }

            $next = $response->getNextPageToken();
            $token = $next === null ? '' : $next->getToken();

            if ($token !== '' && isset($seen[$token])) {
                break;
            }

            $seen[$token] = true;
        } while ($token !== '');

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
