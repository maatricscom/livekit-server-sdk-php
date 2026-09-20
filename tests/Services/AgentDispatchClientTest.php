<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Proto\CreateAgentDispatchRequest;
use LiveKit\Proto\JobRestartPolicy;
use LiveKit\Services\AgentDispatchClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class AgentDispatchClientTest extends TwirpTestCase
{
    public function testCreateDispatchPostsRequestAndReturnsDispatch(): void
    {
        $expected = new AgentDispatch();
        $expected->setId('AD_abc123');
        $expected->setAgentName('test-agent');
        $expected->setRoom('my-room');

        $this->http->pushResponse($this->protoResponse($expected));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $dispatch = $client->createDispatch('my-room', 'test-agent', new CreateDispatchOptions(
            metadata: '{"user":"42"}',
            deployment: 'prod',
            attributes: ['tier' => 'gold', 'locale' => 'tr'],
            restartPolicy: JobRestartPolicy::JRP_NEVER,
        ));

        self::assertSame('AD_abc123', $dispatch->getId());
        self::assertSame('test-agent', $dispatch->getAgentName());

        self::assertSame(1, $this->http->requestCount());
        $request = $this->http->lastRequest();

        $this->assertTwirpRequest($request, 'AgentDispatchService', 'CreateDispatch');

        $sent = $this->decodeRequest(CreateAgentDispatchRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('test-agent', $sent->getAgentName());
        self::assertSame('{"user":"42"}', $sent->getMetadata());
        self::assertSame('prod', $sent->getDeployment());
        self::assertSame(JobRestartPolicy::JRP_NEVER, $sent->getRestartPolicy());
        self::assertSame(
            ['tier' => 'gold', 'locale' => 'tr'],
            iterator_to_array($sent->getAttributes()),
        );

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        $claims = $this->claims($request);
        self::assertSame(self::API_KEY, $claims['iss']);
    }

    public function testCreateDispatchWithoutOptionsSendsOnlyRoomAndAgentName(): void
    {
        $this->http->pushResponse($this->protoResponse(new AgentDispatch()));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $client->createDispatch('my-room', 'test-agent');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'CreateDispatch');

        $sent = $this->decodeRequest(CreateAgentDispatchRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('test-agent', $sent->getAgentName());
        self::assertSame('', $sent->getMetadata());
        self::assertSame('', $sent->getDeployment());
        self::assertSame(JobRestartPolicy::JRP_ON_FAILURE, $sent->getRestartPolicy());
        self::assertCount(0, $sent->getAttributes());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }
}
