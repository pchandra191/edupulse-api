<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\ProvenanceValue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProvenanceValueTest extends KernelTestCase
{
    public function testExtractedProvenance(): void
    {
        $pv = ProvenanceValue::extracted('test', 0.99);
        
        $this->assertSame('test', $pv->value);
        $this->assertSame('EXTRACTED', $pv->status->value);
        $this->assertSame(0.99, $pv->confidence);
        $this->assertTrue($pv->isAvailable());
    }

    public function testInferredProvenance(): void
    {
        $pv = ProvenanceValue::inferred('test', 0.72);
        
        $this->assertSame('test', $pv->value);
        $this->assertSame('INFERRED', $pv->status->value);
        $this->assertSame(0.72, $pv->confidence);
        $this->assertTrue($pv->isAvailable());
    }

    public function testMissingProvenance(): void
    {
        $pv = ProvenanceValue::missing();
        
        $this->assertNull($pv->value);
        $this->assertSame('MISSING', $pv->status->value);
        $this->assertSame(0.0, $pv->confidence);
        $this->assertFalse($pv->isAvailable());
    }

    public function testToArray(): void
    {
        $pv = ProvenanceValue::extracted(42, 0.95);
        $array = $pv->toArray();
        
        $this->assertSame(42, $array['value']);
        $this->assertSame('EXTRACTED', $array['status']);
        $this->assertSame(0.95, $array['confidence']);
    }
}