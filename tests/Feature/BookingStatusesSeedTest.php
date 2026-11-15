<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingStatusesSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_status_ids_exist_after_migration(): void
    {
        $statuses = DB::table('booking_statuses')->orderBy('id')->get(['id', 'slug']);

        $this->assertSame([1 => 'confirmed', 2 => 'cancelled'], $statuses->pluck('slug', 'id')->all());
    }
}
