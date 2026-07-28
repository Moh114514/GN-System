<?php

namespace Tests\Unit;

use App\Modules\Agent\Domain\AgentCodeNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AgentCodeNormalizerTest extends TestCase
{
    public function test_it_normalizes_legacy_korean_agent_and_customer_codes(): void
    {
        $normalizer = new AgentCodeNormalizer;

        $this->assertSame('DY-KR', $normalizer->agent('kr-dy'));
        $this->assertSame('DY-KR-0001', $normalizer->customer('KR-DY-0001'));
        $this->assertSame('SZ-JG', $normalizer->agent('SZ-JG'));
        $this->assertSame('LH-VIP', $normalizer->agent('lh-vip', 'VIP'));
        $this->assertSame('ZX-000001', $normalizer->customer('ZX-000001'));
    }

    public function test_it_rejects_unstructured_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AgentCodeNormalizer)->agent('任意编号');
    }

    public function test_it_rejects_a_code_that_does_not_match_the_selected_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AgentCodeNormalizer)->agent('LH-VIP', 'JG');
    }
}
