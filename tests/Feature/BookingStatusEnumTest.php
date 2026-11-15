<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Garante que o enum BookingStatus e a tabela booking_statuses não divergem.
 */
class BookingStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_enum_mirrors_booking_statuses_table(): void
    {
        $rows = DB::table('booking_statuses')->orderBy('id')->get(['id', 'slug']);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame($row->slug, BookingStatus::from($row->id)->slug());
        }

        $this->assertCount($rows->count(), BookingStatus::cases());
    }
}
