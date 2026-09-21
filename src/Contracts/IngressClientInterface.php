<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\ListIngressResponse;

interface IngressClientInterface
{
    public function createIngress(CreateIngressOptions $options): IngressInfo;

    public function updateIngress(string $ingressId, UpdateIngressOptions $options): IngressInfo;

    /**
     * One request, and the response the server built -- `next_page_token` included.
     * The same shape LiveKit's Go, Python and Ruby SDKs return from ListIngress.
     */
    public function listIngress(?ListIngressOptions $options = null): ListIngressResponse;

    /**
     * Walks every page, asking for the next only once the caller has taken the
     * current one, so stopping early stops the requests too.
     *
     * @return \Generator<int, IngressInfo, mixed, void>
     */
    public function iterateIngress(?ListIngressOptions $options = null): \Generator;

    /**
     * Every page, collected into one array.
     *
     * @return list<IngressInfo>
     */
    public function listAllIngress(?ListIngressOptions $options = null): array;

    public function deleteIngress(string $ingressId): IngressInfo;
}
