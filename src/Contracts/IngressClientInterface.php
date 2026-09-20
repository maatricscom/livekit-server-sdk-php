<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInfo;

interface IngressClientInterface
{
    public function createIngress(CreateIngressOptions $options): IngressInfo;

    public function updateIngress(string $ingressId, UpdateIngressOptions $options): IngressInfo;
}
