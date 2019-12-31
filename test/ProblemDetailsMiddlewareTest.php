<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\ProblemDetails;

use ErrorException;
use Mezzio\ProblemDetails\ProblemDetailsMiddleware;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function trigger_error;

use const E_USER_ERROR;

class ProblemDetailsMiddlewareTest extends TestCase
{
    use ProblemDetailsAssertionsTrait;

    protected function setUp() : void
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->responseFactory = $this->prophesize(ProblemDetailsResponseFactory::class);
        $this->middleware = new ProblemDetailsMiddleware($this->responseFactory->reveal());
    }

    public function acceptHeaders() : array
    {
        return [
            'empty'                    => [''],
            'application/xml'          => ['application/xml'],
            'application/vnd.api+xml'  => ['application/vnd.api+xml'],
            'application/json'         => ['application/json'],
            'application/vnd.api+json' => ['application/vnd.api+json'],
        ];
    }

    public function testSuccessfulDelegationReturnsHandlerResponse() : void
    {
        $response = $this->prophesize(ResponseInterface::class);
        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::that([$this->request, 'reveal']))
            ->will([$response, 'reveal']);


        $result = $this->middleware->process($this->request->reveal(), $handler->reveal());

        $this->assertSame($response->reveal(), $result);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testThrowableRaisedByHandlerResultsInProblemDetails(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $exception = new TestAsset\RuntimeException('Thrown!', 507);

        $handler  = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::that([$this->request, 'reveal']))
            ->willThrow($exception);

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), $exception)
            ->willReturn($expected);

        $result = $this->middleware->process($this->request->reveal(), $handler->reveal());

        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testMiddlewareRegistersErrorHandlerToConvertErrorsToProblemDetails(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::that([$this->request, 'reveal']))
            ->will(function () {
                trigger_error('Triggered error!', E_USER_ERROR);
            });

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), Argument::that(function ($e) {
                $this->assertInstanceOf(ErrorException::class, $e);
                $this->assertEquals(E_USER_ERROR, $e->getSeverity());
                $this->assertEquals('Triggered error!', $e->getMessage());
                return true;
            }))
            ->willReturn($expected);

        $result = $this->middleware->process($this->request->reveal(), $handler->reveal());

        $this->assertSame($expected, $result);
    }

    public function testRethrowsCaughtExceptionIfUnableToNegotiateAcceptHeader() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('text/html');
        $exception = new TestAsset\RuntimeException('Thrown!', 507);
        $handler  = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::that([$this->request, 'reveal']))
            ->willThrow($exception);

        $this->expectException(TestAsset\RuntimeException::class);
        $this->expectExceptionMessage('Thrown!');
        $this->expectExceptionCode(507);
        $this->middleware->process($this->request->reveal(), $handler->reveal());
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testErrorHandlingTriggersListeners(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $exception = new TestAsset\RuntimeException('Thrown!', 507);

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::that([$this->request, 'reveal']))
            ->willThrow($exception);

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), $exception)
            ->willReturn($expected);

        $listener = function ($error, $request, $response) use ($exception, $expected) {
            $this->assertSame($exception, $error, 'Listener did not receive same exception as was raised');
            $this->assertSame($this->request->reveal(), $request, 'Listener did not receive same request');
            $this->assertSame($expected, $response, 'Listener did not receive same response');
        };
        $listener2 = clone $listener;
        $this->middleware->attachListener($listener);
        $this->middleware->attachListener($listener2);

        $result = $this->middleware->process($this->request->reveal(), $handler->reveal());

        $this->assertSame($expected, $result);
    }
}
