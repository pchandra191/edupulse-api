<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Service\Analysis\AnalysisModeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AnalysisModeResolverTest extends TestCase
{
    private function createResolver(string $mode): AnalysisModeResolver
    {
        $logger = $this->createMock(LoggerInterface::class);
        return new AnalysisModeResolver($mode, $logger);
    }

    public function testDirectModeIsResolved(): void
    {
        $resolver = $this->createResolver('direct');
        $this->assertTrue($resolver->isDirectMode());
        $this->assertFalse($resolver->isLegacyMode());
    }

    public function testLegacyModeIsResolved(): void
    {
        $resolver = $this->createResolver('legacy');
        $this->assertFalse($resolver->isDirectMode());
        $this->assertTrue($resolver->isLegacyMode());
    }

    public function testUnknownModeDefaultsToLegacy(): void
    {
        $resolver = $this->createResolver('unknown');
        $this->assertFalse($resolver->isDirectMode());
        $this->assertTrue($resolver->isLegacyMode());
    }

    public function testModeIsCaseInsensitive(): void
    {
        $resolver = $this->createResolver('DIRECT');
        $this->assertTrue($resolver->isDirectMode());
    }
}
