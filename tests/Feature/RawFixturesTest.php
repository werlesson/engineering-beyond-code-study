<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RawFixtures;
use Tests\TestCase;

class RawFixturesTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    public function test_queries_still_work_after_expected_constraint_failure(): void
    {
        $companyId = $this->company();

        $this->assertSqlState('23514', fn () => $this->service($companyId, 0));

        $this->assertSame(1, DB::table('companies')->count());

        $this->company('Another');
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('services', 0);
    }

    public function test_assert_sql_state_fails_when_no_exception_is_thrown(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('no exception was thrown');

        $this->assertSqlState('23514', fn () => $this->company());
    }

    public function test_assert_sql_state_fails_when_state_differs(): void
    {
        $companyId = $this->company();

        $this->expectException(AssertionFailedError::class);

        $this->assertSqlState('23503', fn () => $this->service($companyId, 0));
    }

    public function test_bookable_offer_accepts_a_valid_booking(): void
    {
        $this->freezeClock();

        $bookingId = $this->booking($this->bookableOffer() + [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status_id' => 1]);
    }
}
