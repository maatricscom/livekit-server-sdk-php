<?php

declare(strict_types=1);

namespace LiveKit\Tests\Enums;

use LiveKit\Enums\ProtoEnum;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\IngressInput;
use LiveKit\Proto\SIPTransport;
use LiveKit\Services\IngressClient;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * protoc emits enums as classes of integer constants, and the generated setters
 * take any integer at all — setInputType(99) is accepted, encoded and sent. The
 * server then reads a value its own enum does not define, and nothing along the
 * way said anything was wrong.
 *
 * The mistake worth catching is not an invented number but a constant borrowed
 * from the neighbouring enum: SIPTransport where SIPHeaderOptions was meant,
 * both of them plain ints, both of them in range.
 */
final class ProtoEnumTest extends TestCase
{
    /** @return iterable<string, array{class-string, int}> */
    public static function definedValues(): iterable
    {
        yield 'a non-zero constant' => [IngressInput::class, IngressInput::WHIP_INPUT];
        // Zero is a real value in most LiveKit enums, not an "unset" marker, so it
        // must not be rejected as if it were one.
        yield 'zero, which is meaningful here' => [SIPTransport::class, SIPTransport::SIP_TRANSPORT_AUTO];
        yield 'zero again, in another enum' => [Kind::class, Kind::RELIABLE];
    }

    /** @param class-string $enum */
    #[DataProvider('definedValues')]
    public function test_a_value_the_enum_defines_passes_through_unchanged(string $enum, int $value): void
    {
        self::assertSame($value, ProtoEnum::check($enum, $value, 'field'));
    }

    /** @return iterable<string, array{int}> */
    public static function undefinedValues(): iterable
    {
        yield 'far out of range' => [99];
        yield 'negative' => [-1];
        yield 'one past the last constant' => [count(ProtoEnum::names(IngressInput::class))];
    }

    #[DataProvider('undefinedValues')]
    public function test_a_value_the_enum_does_not_define_is_refused(int $value): void
    {
        $this->expectException(ConfigurationException::class);

        ProtoEnum::check(IngressInput::class, $value, 'inputType');
    }

    public function test_the_message_names_the_field_the_enum_and_the_valid_constants(): void
    {
        try {
            ProtoEnum::check(SIPTransport::class, 42, 'transport');
            self::fail('Expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('$transport', $e->getMessage());
            self::assertStringContainsString('SIPTransport', $e->getMessage());
            self::assertStringContainsString('SIP_TRANSPORT_AUTO', $e->getMessage());
        }
    }

    public function test_the_accepted_set_is_read_from_the_generated_class(): void
    {
        // Written out here, it would drift from the pinned protocol the first time
        // LiveKit adds a value. Read from the class, regenerating is all it takes.
        $names = ProtoEnum::names(IngressInput::class);

        self::assertArrayHasKey('RTMP_INPUT', $names);
        self::assertSame(IngressInput::RTMP_INPUT, $names['RTMP_INPUT']);

        foreach ($names as $value) {
            self::assertSame($value, ProtoEnum::check(IngressInput::class, $value, 'inputType'));
        }
    }

    public function test_a_client_refuses_an_out_of_range_enum_rather_than_sending_it(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, [], ''));

        $client = new IngressClient(
            'https://x.livekit.cloud',
            self::API_KEY,
            self::API_SECRET,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->createIngress(new CreateIngressOptions(inputType: 99));
            self::fail('Expected a ConfigurationException');
        } catch (ConfigurationException) {
        }

        self::assertSame(0, $http->requestCount(), 'The request must not leave with a value the server cannot read.');
    }
}
