<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Resolver\ResolverPipeline;
use PHPUnit\Framework\TestCase;

class ResolverPipelineTest extends TestCase
{
    public function testAddResolver(): void
    {
        $pipeline = new ResolverPipeline();
        $resolver = $this->createMock(ParameterResolverInterface::class);

        $result = $pipeline->addResolver($resolver);

        $this->assertSame($pipeline, $result);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGetParameters(): void
    {
        $pipeline = new ResolverPipeline();
        $reflection = new \ReflectionFunction(function ($param1, $param2) {});
        $providedParameters = ['param1' => 'value1'];
        $initialResolvedParameters = [];

        $resolver1 = $this->createMock(ParameterResolverInterface::class);
        $resolver1->expects($this->once())
            ->method('getParameters')
            ->with($reflection, $providedParameters, $initialResolvedParameters)
            ->willReturn(['param1' => 'value1']);

        $resolver2 = $this->createMock(ParameterResolverInterface::class);
        $resolver2->expects($this->once())
            ->method('getParameters')
            ->with($reflection, $providedParameters, ['param1' => 'value1'])
            ->willReturn(['param1' => 'value1', 'param2' => 'value2']);

        $pipeline->addResolver($resolver1)->addResolver($resolver2);

        $result = $pipeline->getParameters($reflection, $providedParameters, $initialResolvedParameters);

        $this->assertEquals(['param1' => 'value1', 'param2' => 'value2'], $result);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGetParametersStopsWhenAllResolved(): void
    {
        $pipeline = new ResolverPipeline();
        $reflection = new \ReflectionFunction(function ($param1) {});
        $providedParameters = [];
        $initialResolvedParameters = [];

        $resolver1 = $this->createMock(ParameterResolverInterface::class);
        $resolver1->expects($this->once())
            ->method('getParameters')
            ->willReturn(['param1' => 'value1']);

        $resolver2 = $this->createMock(ParameterResolverInterface::class);
        $resolver2->expects($this->never())
            ->method('getParameters');

        $pipeline->addResolver($resolver1)->addResolver($resolver2);

        $result = $pipeline->getParameters($reflection, $providedParameters, $initialResolvedParameters);

        $this->assertEquals(['param1' => 'value1'], $result);
    }
}
