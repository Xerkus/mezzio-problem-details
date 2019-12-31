<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\ProblemDetails;

use Exception;
use Mezzio\ProblemDetails\Exception\ProblemDetailsExceptionInterface;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function array_keys;
use function fclose;
use function fopen;
use function json_decode;
use function stripos;

class ProblemDetailsResponseFactoryTest extends TestCase
{
    use ProblemDetailsAssertionsTrait;

    /** @var ServerRequestInterface|ObjectProphecy */
    private $request;

    /** @var ResponseInterface|ObjectProphecy */
    private $response;

    /** @var ProblemDetailsResponseFactory */
    private $factory;

    private const UTF_8_INVALID_2_OCTET_SEQUENCE = "\xc3\x28";

    protected function setUp() : void
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->response = $this->prophesize(ResponseInterface::class);
        $this->factory = new ProblemDetailsResponseFactory(function () {
            return $this->response->reveal();
        });
    }

    public function acceptHeaders() : array
    {
        return [
            'empty'                    => ['', 'application/problem+json'],
            'application/xml'          => ['application/xml', 'application/problem+xml'],
            'application/vnd.api+xml'  => ['application/vnd.api+xml', 'application/problem+xml'],
            'application/json'         => ['application/json', 'application/problem+json'],
            'application/vnd.api+json' => ['application/vnd.api+json', 'application/problem+json'],
        ];
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseCreatesExpectedType(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $stream = $this->prophesize(StreamInterface::class);
        $stream->write(Argument::type('string'))->shouldBeCalled();

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', $expectedType)->will([$this->response, 'reveal']);

        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred'
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseFromThrowableCreatesExpectedType(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $stream = $this->prophesize(StreamInterface::class);
        $stream->write(Argument::type('string'))->shouldBeCalled();

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', $expectedType)->will([$this->response, 'reveal']);

        $exception = new RuntimeException();
        $response = $this->factory->createResponseFromThrowable(
            $this->request->reveal(),
            $exception
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseFromThrowableCreatesExpectedTypeWithExtraInformation(
        string $header,
        string $expectedType
    ) : void {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $stream = $this->prophesize(StreamInterface::class);
        $this->prepareResponsePayloadAssertions($expectedType, $stream, function (array $payload) {
            Assert::assertArrayHasKey('exception', $payload);
        });

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', $expectedType)->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(
            function () {
                return $this->response->reveal();
            },
            ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS
        );

        $exception = new RuntimeException();
        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $exception
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseRemovesInvalidCharactersFromXmlKeys(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $additional = [
            'foo' => [
                'A#-'          => 'foo',
                '-A-'          => 'foo',
                '#B-'          => 'foo',
                "C\n-"         => 'foo',
                chr(10) . 'C-' => 'foo',
            ],
        ];

        if (stripos($expectedType, 'xml')) {
            $expectedKeyNames = [
                'A_-',
                '_A-',
                '_B-',
                'C_-',
                '_C-',
            ];
        } else {
            $expectedKeyNames = array_keys($additional['foo']);
        }

        $stream = $this->prophesize(StreamInterface::class);
        $this->prepareResponsePayloadAssertions(
            $expectedType,
            $stream,
            function (array $payload) use ($expectedKeyNames) {
                Assert::assertEquals($expectedKeyNames, array_keys($payload['foo']));
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', $expectedType)->will([$this->response, 'reveal']);

        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred',
            'Title',
            'Type',
            $additional
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testCreateResponseFromThrowableWillPullDetailsFromProblemDetailsExceptionInterface() : void
    {
        $e = $this->prophesize(RuntimeException::class)->willImplement(ProblemDetailsExceptionInterface::class);
        $e->getStatus()->willReturn(400);
        $e->getDetail()->willReturn('Exception details');
        $e->getTitle()->willReturn('Invalid client request');
        $e->getType()->willReturn('https://example.com/api/doc/invalid-client-request');
        $e->getAdditionalData()->willReturn(['foo' => 'bar']);

        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) {
                Assert::assertSame(400, $payload['status']);
                Assert::assertSame('Exception details', $payload['detail']);
                Assert::assertSame('Invalid client request', $payload['title']);
                Assert::assertSame('https://example.com/api/doc/invalid-client-request', $payload['type']);
                Assert::assertSame('bar', $payload['foo']);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(400)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(function () {
            return $this->response->reveal();
        });

        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $e->reveal()
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseRemovesResourcesFromInputData(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $stream = $this->prophesize(StreamInterface::class);
        $stream
            ->write(Argument::that(function ($body) {
                Assert::assertNotEmpty($body);
                return $body;
            }))
            ->shouldBeCalled();

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', $expectedType)->will([$this->response, 'reveal']);

        $fh = fopen(__FILE__, 'r');
        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred',
            'Title',
            'Type',
            [
                'args' => [
                    'resource' => $fh,
                ]
            ]
        );
        fclose($fh);

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testFactoryGeneratesXmlResponseIfNegotiationFails() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('text/plain');

        $stream = $this->prophesize(StreamInterface::class);
        $stream
            ->write(Argument::type('string'))
            ->shouldBeCalled();

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+xml')->will([$this->response, 'reveal']);

        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred'
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testFactoryRendersPreviousExceptionsInDebugMode() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) {
                Assert::assertArrayHasKey('exception', $payload);
                Assert::assertEquals(101011, $payload['exception']['code']);
                Assert::assertEquals('second', $payload['exception']['message']);
                Assert::assertArrayHasKey('stack', $payload['exception']);
                Assert::assertInternalType('array', $payload['exception']['stack']);
                Assert::assertEquals(101010, $payload['exception']['stack'][0]['code']);
                Assert::assertEquals('first', $payload['exception']['stack'][0]['message']);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $first = new RuntimeException('first', 101010);
        $second = new RuntimeException('second', 101011, $first);

        $factory = new ProblemDetailsResponseFactory(
            function () {
                return $this->response->reveal();
            },
            ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS
        );

        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $second
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testFragileDataInExceptionMessageShouldBeHiddenInResponseBodyInNoDebugMode()
    {
        $fragileMessage = 'Your SQL or password here';
        $exception = new Exception($fragileMessage);

        $stream = $this->prophesize(StreamInterface::class);
        $stream
            ->write(Argument::that(function ($body) use ($fragileMessage) {
                Assert::assertNotContains($fragileMessage, $body);
                Assert::assertContains(ProblemDetailsResponseFactory::DEFAULT_DETAIL_MESSAGE, $body);
                return $body;
            }))
            ->shouldBeCalled();

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $response = $this->factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testExceptionCodeShouldBeIgnoredAnd500ServedInResponseBodyInNonDebugMode()
    {
        $exception = new Exception('', 400);

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) {
                Assert::assertSame(500, $payload['status']);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $response = $this->factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testFragileDataInExceptionMessageShouldBeVisibleInResponseBodyInNonDebugModeWhenAllowToShowByFlag()
    {
        $fragileMessage = 'Your SQL or password here';
        $exception = new Exception($fragileMessage);

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) use ($fragileMessage) {
                Assert::assertSame($fragileMessage, $payload['detail']);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(
            function () {
                return $this->response->reveal();
            },
            false,
            null,
            true
        );

        $response = $factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testCustomDetailMessageShouldBeVisible()
    {
        $detailMessage = 'Custom detail message';

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) use ($detailMessage) {
                Assert::assertSame($detailMessage, $payload['detail']);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(500)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(
            function () {
                return $this->response->reveal();
            },
            false,
            null,
            false,
            $detailMessage
        );

        $response = $factory->createResponseFromThrowable($this->request->reveal(), new Exception());

        $this->assertSame($this->response->reveal(), $response);
    }

    public function testRenderWithMalformedUtf8Sequences(): void
    {
        $e = $this->prophesize(RuntimeException::class)->willImplement(ProblemDetailsExceptionInterface::class);
        $e->getStatus()->willReturn(400);
        $e->getDetail()->willReturn('Exception details');
        $e->getTitle()->willReturn('Invalid client request');
        $e->getType()->willReturn('https://example.com/api/doc/invalid-client-request');
        $e->getAdditionalData()->willReturn([
            'malformed-utf8' => self::UTF_8_INVALID_2_OCTET_SEQUENCE,
        ]);

        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $stream = $this->prophesize(StreamInterface::class);
        $this->preparePayloadForJsonResponse(
            $stream,
            function (array $payload) {
                Assert::arrayHasKey('malformed-utf8', $payload);
            }
        );

        $this->response->getBody()->will([$stream, 'reveal']);
        $this->response->withStatus(400)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(function () {
            return $this->response->reveal();
        });

        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $e->reveal()
        );

        $this->assertSame($this->response->reveal(), $response);
    }

    public function provideMappedStatuses() : array
    {
        $defaultTypesMap = [
            404 => 'https://example.com/problem-details/error/not-found',
            500 => 'https://example.com/problem-details/error/internal-server-error',
        ];

        return [
            [$defaultTypesMap, 404, 'https://example.com/problem-details/error/not-found'],
            [$defaultTypesMap, 500, 'https://example.com/problem-details/error/internal-server-error'],
            [$defaultTypesMap, 400, 'https://httpstatus.es/400'],
            [[], 500, 'https://httpstatus.es/500'],
        ];
    }

    /**
     * @dataProvider provideMappedStatuses
     */
    public function testTypeIsInferredFromDefaultTypesMap(array $map, int $status, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $stream = $this->prophesize(StreamInterface::class);
        $writeStream = $stream->write(Argument::that(function (string $body) use ($expectedType) {
            $payload = json_decode($body, true);
            Assert::assertEquals($expectedType, $payload['type']);

            return $body;
        }));

        $this->response->getBody()->will([$stream, 'reveal']);
        $withStatus = $this->response->withStatus($status)->will([$this->response, 'reveal']);
        $this->response->withHeader('Content-Type', 'application/problem+json')->will([$this->response, 'reveal']);

        $factory = new ProblemDetailsResponseFactory(
            function () {
                return $this->response->reveal();
            },
            false,
            null,
            false,
            '',
            $map
        );

        $factory->createResponse($this->request->reveal(), $status, 'detail');

        $writeStream->shouldHaveBeenCalled();
        $withStatus->shouldHaveBeenCalled();
    }
}
