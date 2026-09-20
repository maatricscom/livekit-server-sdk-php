<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Proto\CreateAgentDispatchRequest;
use LiveKit\Proto\DeleteAgentDispatchRequest;
use LiveKit\Proto\JobRestartPolicy;
use LiveKit\Proto\ListAgentDispatchRequest;
use LiveKit\Proto\ListAgentDispatchResponse;
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
        $attributes = iterator_to_array($sent->getAttributes());
        // A protobuf map is unordered. ext-protobuf iterates it in hash order, which
        // differs between processes, so the pairs are the assertion -- not the order.
        ksort($attributes);
        self::assertSame(['locale' => 'tr', 'tier' => 'gold'], $attributes);

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

    public function testDeleteDispatchSendsDispatchIdAndRoom(): void
    {
        $expected = new AgentDispatch();
        $expected->setId('AD_abc123');
        $expected->setRoom('my-room');

        $this->http->pushResponse($this->protoResponse($expected));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $dispatch = $client->deleteDispatch('AD_abc123', 'my-room');

        self::assertSame('AD_abc123', $dispatch->getId());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'DeleteDispatch');

        $sent = $this->decodeRequest(DeleteAgentDispatchRequest::class);
        self::assertSame('AD_abc123', $sent->getDispatchId());
        self::assertSame('my-room', $sent->getRoom());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    private function dispatchClient(): AgentDispatchClient
    {
        return new AgentDispatchClient(self::HOST, self::API_KEY, self::API_SECRET, httpClient: $this->http);
    }

    public function testGetDispatchReturnsTheOneDispatchTheServerMatched(): void
    {
        $only = new AgentDispatch();
        $only->setId('AD_1');
        $only->setRoom('my-room');
        $only->setAgentName('test-agent');

        $listResponse = new ListAgentDispatchResponse();
        $listResponse->setAgentDispatches([$only]);

        $this->http->pushResponse($this->protoResponse($listResponse));

        $dispatch = $this->dispatchClient()->getDispatch('AD_1', 'my-room');

        self::assertInstanceOf(AgentDispatch::class, $dispatch);
        self::assertSame('AD_1', $dispatch->getId());
        self::assertSame('test-agent', $dispatch->getAgentName());

        // There is no GetDispatch rpc: this is ListDispatch filtered by dispatch_id.
        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'ListDispatch');
        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        $sent = $this->decodeRequest(ListAgentDispatchRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('AD_1', $sent->getDispatchId());
    }

    public function testGetDispatchReturnsNullWhenTheRoomHasNoSuchDispatch(): void
    {
        // An unknown id is not an error on this rpc: the server answers with an
        // empty list. Returning null is what makes that answerable with `??`
        // rather than by checking an array the caller did not ask for.
        $this->http->pushResponse($this->protoResponse(new ListAgentDispatchResponse()));

        self::assertNull($this->dispatchClient()->getDispatch('AD_missing', 'my-room'));
    }

    public function testGetDispatchTakesTheFirstWhenTheServerReturnsMoreThanOne(): void
    {
        // dispatch_id identifies one dispatch, so this should not happen. If it
        // ever does, answering with the first is better than throwing at a caller
        // who asked a perfectly reasonable question.
        $first = new AgentDispatch();
        $first->setId('AD_1');
        $second = new AgentDispatch();
        $second->setId('AD_2');

        $listResponse = new ListAgentDispatchResponse();
        $listResponse->setAgentDispatches([$first, $second]);

        $this->http->pushResponse($this->protoResponse($listResponse));

        self::assertSame('AD_1', $this->dispatchClient()->getDispatch('AD_1', 'my-room')?->getId());
    }

    public function testGetDispatchIssuesExactlyOneRequest(): void
    {
        $listResponse = new ListAgentDispatchResponse();
        $listResponse->setAgentDispatches([new AgentDispatch()]);

        $this->http->pushResponse($this->protoResponse($listResponse));

        $this->dispatchClient()->getDispatch('AD_1', 'my-room');

        self::assertSame(1, $this->http->requestCount());
    }

    public function testListDispatchReturnsEveryDispatchInTheRoom(): void
    {
        $first = new AgentDispatch();
        $first->setId('AD_1');
        $second = new AgentDispatch();
        $second->setId('AD_2');

        $listResponse = new ListAgentDispatchResponse();
        $listResponse->setAgentDispatches([$first, $second]);

        $this->http->pushResponse($this->protoResponse($listResponse));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $dispatches = $client->listDispatch('my-room');

        self::assertCount(2, $dispatches);
        self::assertContainsOnlyInstancesOf(AgentDispatch::class, $dispatches);
        self::assertSame('AD_1', $dispatches[0]->getId());
        self::assertSame('AD_2', $dispatches[1]->getId());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'ListDispatch');

        $sent = $this->decodeRequest(ListAgentDispatchRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('', $sent->getDispatchId());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testListDispatchFiltersByDispatchId(): void
    {
        $only = new AgentDispatch();
        $only->setId('AD_1');
        $only->setRoom('my-room');

        $listResponse = new ListAgentDispatchResponse();
        $listResponse->setAgentDispatches([$only]);

        $this->http->pushResponse($this->protoResponse($listResponse));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $dispatches = $client->listDispatch('my-room', 'AD_1');

        self::assertCount(1, $dispatches);
        self::assertSame('AD_1', $dispatches[0]->getId());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'ListDispatch');

        $sent = $this->decodeRequest(ListAgentDispatchRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('AD_1', $sent->getDispatchId());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testListDispatchReturnsEmptyArrayWhenRoomHasNoDispatches(): void
    {
        $this->http->pushResponse($this->protoResponse(new ListAgentDispatchResponse()));

        $client = new AgentDispatchClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        self::assertSame([], $client->listDispatch('empty-room'));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'AgentDispatchService', 'ListDispatch');

        $sent = $this->decodeRequest(ListAgentDispatchRequest::class);
        self::assertSame('empty-room', $sent->getRoom());
        self::assertSame('', $sent->getDispatchId());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'empty-room'], $request);
    }
}
