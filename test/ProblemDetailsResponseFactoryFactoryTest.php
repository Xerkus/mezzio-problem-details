<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\ProblemDetails;

use Closure;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactoryFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use TypeError;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

class ProblemDetailsResponseFactoryFactoryTest extends TestCase
{
    protected function setUp() : void
    {
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function assertResponseFactoryReturns(ResponseInterface $expected, ProblemDetailsResponseFactory $factory)
    {
        $r = new ReflectionProperty($factory, 'responseFactory');
        $r->setAccessible(true);
        $responseFactory = $r->getValue($factory);

        Assert::assertSame($expected, $responseFactory());
    }

    public function testLackOfResponseServiceResultsInException()
    {
        $factory = new ProblemDetailsResponseFactoryFactory();
        $e = new RuntimeException();

        $this->container->has('config')->willReturn(false);
        $this->container->get('config')->shouldNotBeCalled();
        $this->container->get(ResponseInterface::class)->willThrow($e);

        $this->expectException(RuntimeException::class);
        $factory($this->container->reveal());
    }

    public function testNonCallableResponseServiceResultsInException()
    {
        $factory = new ProblemDetailsResponseFactoryFactory();

        $this->container->has('config')->willReturn(false);
        $this->container->get('config')->shouldNotBeCalled();
        $this->container->get(ResponseInterface::class)->willReturn(new stdClass);

        $this->expectException(TypeError::class);
        $factory($this->container->reveal());
    }

    public function testLackOfConfigServiceResultsInFactoryUsingDefaults() : void
    {
        $this->container->has('config')->willReturn(false);

        $response = $this->prophesize(ResponseInterface::class)->reveal();
        $this->container->get(ResponseInterface::class)->willReturn(function () use ($response) {
            return $response;
        });

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(ProblemDetailsResponseFactory::EXCLUDE_THROWABLE_DETAILS, 'isDebug', $factory);
        $this->assertAttributeSame(
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_PARTIAL_OUTPUT_ON_ERROR,
            'jsonFlags',
            $factory
        );

        $this->assertAttributeInstanceOf(Closure::class, 'responseFactory', $factory);
        $this->assertResponseFactoryReturns($response, $factory);
    }

    public function testUsesPrettyPrintFlagOnEnabledDebugMode() : void
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'debug' => true,
        ]);
        $this->container->get(ResponseInterface::class)->willReturn(function () {
        });

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertSame(JSON_PRETTY_PRINT, Assert::readAttribute($factory, 'jsonFlags') & JSON_PRETTY_PRINT);
    }

    public function testUsesDebugSettingFromConfigWhenPresent() : void
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn(['debug' => true]);

        $this->container->get(ResponseInterface::class)->willReturn(function () {
        });

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS, 'isDebug', $factory);
        $this->assertAttributeSame(true, 'exceptionDetailsInResponse', $factory);
    }

    public function testUsesJsonFlagsSettingFromConfigWhenPresent() : void
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn(['problem-details' => ['json_flags' => JSON_PRETTY_PRINT]]);

        $this->container->get(ResponseInterface::class)->willReturn(function () {
        });

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(JSON_PRETTY_PRINT, 'jsonFlags', $factory);
    }

    public function testUsesDefaultTypesSettingFromConfigWhenPresent() : void
    {
        $expectedDefaultTypes = [
            404 => 'https://example.com/problem-details/error/not-found',
        ];

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn(
            ['problem-details' => ['default_types_map' => $expectedDefaultTypes]]
        );

        $this->container->get(ResponseInterface::class)->willReturn(function () {
        });

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame($expectedDefaultTypes, 'defaultTypesMap', $factory);
    }
}
