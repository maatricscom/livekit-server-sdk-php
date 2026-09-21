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

    /** One request, and the response as the server sent it, cursor included. */
    public function listIngressPage(?ListIngressOptions $options = null): ListIngressResponse;

    /**
     * Walks every page, fetching the next only once the current one is spent.
     *
     * @return \Generator<int, IngressInfo, mixed, void>
     */
    public function iterateIngress(?ListIngressOptions $options = null): \Generator;

    /** @return list<IngressInfo> */
    public function listIngress(?ListIngressOptions $options = null): array;

    public function deleteIngress(string $ingressId): IngressInfo;
}
