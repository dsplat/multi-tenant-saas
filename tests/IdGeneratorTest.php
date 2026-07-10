<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\IdGenerator;

class IdGeneratorTest extends TestCase
{
    public function test_generate_returns_16_digit_id(): void
    {
        $generator = new IdGenerator;
        $id = $generator->generate();
        $this->assertIsInt($id);
        $this->assertEquals(16, strlen((string) $id));
    }

    public function test_generated_id_is_in_range(): void
    {
        $generator = new IdGenerator;
        $id = $generator->generate();
        $this->assertGreaterThanOrEqual(1000000000000000, $id);
        $this->assertLessThanOrEqual(9007199254740991, $id);
    }

    public function test_batch_generates_correct_count(): void
    {
        $generator = new IdGenerator;
        $ids = $generator->batch(5);
        $this->assertCount(5, $ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertEquals(16, strlen((string) $id));
        }
    }

    public function test_validate_accepts_valid_id(): void
    {
        $generator = new IdGenerator;
        $this->assertTrue($generator->validate(1000000000000000));
        $this->assertTrue($generator->validate(9007199254740991));
    }

    public function test_validate_rejects_invalid_id(): void
    {
        $generator = new IdGenerator;
        $this->assertFalse($generator->validate(123));
        $this->assertFalse($generator->validate(99999999999999999));
    }

    public function test_is_js_safe(): void
    {
        $generator = new IdGenerator;
        $this->assertTrue($generator->isJsSafe(9007199254740991));
        $this->assertFalse($generator->isJsSafe(9007199254740992));
    }

    public function test_parse_id_returns_correct_info(): void
    {
        $generator = new IdGenerator;
        $id = 1234567890123456;
        $info = $generator->parseId($id);
        $this->assertEquals($id, $info['id']);
        $this->assertEquals(16, $info['length']);
        $this->assertTrue($info['valid']);
    }
}
