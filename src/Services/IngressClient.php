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

    /** One request, and the response as the server sent it -- cursor included. */
    public function listIngressPage(?ListIngressOptions $options = null): ListIngressResponse
    {
        return $this->ingressPage($options, $options->pageToken ?? '');
    }

    /**
     * @return \Generator<int, IngressInfo, mixed, void>
     */
    public function iterateIngress(?ListIngressOptions $options = null): \Generator
    {
        $token = $options->pageToken ?? '';
        $seen = [];

        do {
            $response = $this->ingressPage($options, $token);

            foreach ($response->getItems() as $item) {
                yield $item;
            }

            $next = $response->getNextPageToken();
            $token = $next === null ? '' : $next->getToken();

            if ($token !== '' && isset($seen[$token])) {
                break;
            }

            $seen[$token] = true;
        } while ($token !== '');
    }

    /**
     * @return list<IngressInfo>
     */
    public function listIngress(?ListIngressOptions $options = null): array
    {
        // See EgressClient::listEgress() for why the cursor is walked rather than
        // returned. A LiveKit Cloud project caps ingress objects well below any
        // page size -- measured at 40 with no cursor, and refused at about 50 --
        // so this is expected to make one request. It is here because the RPC can
        // paginate, not because this one is known to.
        return iterator_to_array($this->iterateIngress($options), false);
    }

    /** One request. The token is passed rather than read off the options so the walk can advance it. */
    private function ingressPage(?ListIngressOptions $options, string $token): ListIngressResponse
    {
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

        return $this->rpc(
            self::SERVICE,
            'ListIngress',
            $request,
            ListIngressResponse::class,
            $this->authHeader(new VideoGrant(ingressAdmin: true)),
        );
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
