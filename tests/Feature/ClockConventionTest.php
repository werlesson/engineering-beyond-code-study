<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClockConventionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_freeze_clock_pins_now(): void
    {
        $this->assertSame('2026-01-01T12:00:00Z', now()->toIso8601ZuluString());
        $this->assertSame(self::FROZEN_NOW, now()->addHour()->subHour()->toIso8601ZuluString());
    }
}
