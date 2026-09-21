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

    /**
     * One request, and the response as the server built it -- `next_page_token`
     * included. This is what LiveKit's Go, Python and Ruby SDKs return from
     * ListIngress, and the reason this one call is not unwrapped to an array like
     * every other list in this package: unwrapping would throw the cursor away.
     */
    /**
     * One request, and the response as the server built it -- `next_page_token`
     * included. This is what LiveKit's Go, Python and Ruby SDKs return from
     * ListIngress, and the reason this one call is not unwrapped to an array like
     * every other list in this package: unwrapping would throw the cursor away.
     *
     * iterateIngress() and listAllIngress() walk the cursor when that is what you want.
     */
    public function listIngress(?ListIngressOptions $options = null): ListIngressResponse
    {
        return $this->ingressPage($options, $options->pageToken ?? '');
    }

    /**
     * Walks every page, asking for the next only once the caller has taken the
     * current one -- so leaving the loop early leaves the rest of the requests
     * unmade, and memory stays at one page.
     *
     * @return \Generator<int, IngressInfo, mixed, void>
     */
    public function iterateIngress(?ListIngressOptions $options = null): \Generator
    {
        $token = $options->pageToken ?? '';
        $seen = [];

        do {
            // The private builder rather than listIngress(): the token is a separate
            // argument here, so nothing has to rebuild the readonly options per
            // page -- a copy that would silently drop any field added to them
            // later and not added to the copy.
            $response = $this->ingressPage($options, $token);

            foreach ($response->getItems() as $item) {
                yield $item;
            }

            $next = $response->getNextPageToken();
            $token = $next === null ? '' : $next->getToken();

            // A server handing back a cursor it has already given would
            // otherwise be followed forever.
            if ($token !== '' && isset($seen[$token])) {
                break;
            }

            $seen[$token] = true;
        } while ($token !== '');
    }

    /**
     * Every page, collected. Its presence beside listIngress() is also the hint that
     * listIngress() is one page: an array that stopped at a boundary would look
     * exactly like a complete one, and nothing in the signature could say so.
     *
     * @return list<IngressInfo>
     */
    public function listAllIngress(?ListIngressOptions $options = null): array
    {
        return iterator_to_array($this->iterateIngress($options), false);
    }

    /** One request. The token is an argument so the walk can advance it without copying the options. */
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
