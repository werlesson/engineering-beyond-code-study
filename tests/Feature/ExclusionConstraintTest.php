<?php

namespace Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RawFixtures;
use Tests\TestCase;

/**
 * Exclusion constraint bookings_no_overlap provada com inserts diretos, contornando a aplicação.
 */
class ExclusionConstraintTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    private const EXCLUSION_VIOLATION = '23P01';

    private const CONFIRMED = 1;

    private const CANCELLED = 2;

    /**
     * @var array{company_id: int, professional_id: int, customer_id: int, service_id: int}
     */
    private array $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        $this->offer = $this->bookableOffer();
    }

    public function test_overlapping_confirmed_bookings_same_professional_fail(): void
    {
        $this->booking($this->offer + $this->slot(14, 15));

        $this->assertSqlState(self::EXCLUSION_VIOLATION, fn () => $this->booking($this->offer + [
            'starts_at' => $this->tomorrowAt(14)->addMinutes(30),
            'ends_at' => $this->tomorrowAt(15)->addMinutes(30),
        ]));

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_adjacent_bookings_are_allowed(): void
    {
        $this->booking($this->offer + $this->slot(14, 15));
        $this->booking($this->offer + $this->slot(15, 16));

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_cancelled_booking_does_not_block_slot(): void
    {
        $this->booking($this->offer + $this->slot(14, 15) + ['status_id' => self::CANCELLED]);
        $this->booking($this->offer + $this->slot(14, 15));

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_overlap_for_different_professionals_is_allowed(): void
    {
        $otherProfessionalId = $this->professional($this->offer['company_id'], 'Other professional');
        $this->offer($this->offer['company_id'], $otherProfessionalId, $this->offer['service_id']);

        $this->booking($this->offer + $this->slot(14, 15));
        $this->booking(['professional_id' => $otherProfessionalId] + $this->offer + $this->slot(14, 15));

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_reconfirming_cancelled_booking_over_confirmed_fails(): void
    {
        $cancelledId = $this->booking($this->offer + $this->slot(14, 15) + ['status_id' => self::CANCELLED]);
        $this->booking($this->offer + $this->slot(14, 15));

        $this->assertSqlState(
            self::EXCLUSION_VIOLATION,
            fn () => DB::table('bookings')->where('id', $cancelledId)->update(['status_id' => self::CONFIRMED]),
        );

        $this->assertDatabaseHas('bookings', ['id' => $cancelledId, 'status_id' => self::CANCELLED]);
    }

    /**
     * @return array{starts_at: CarbonInterface, ends_at: CarbonInterface}
     */
    private function slot(int $startHour, int $endHour): array
    {
        return [
            'starts_at' => $this->tomorrowAt($startHour),
            'ends_at' => $this->tomorrowAt($endHour),
        ];
    }

    /**
     * Horário de amanhã relativo ao relógio congelado.
     */
    private function tomorrowAt(int $hour): CarbonInterface
    {
        return now()->addDay()->setTime($hour, 0);
    }
}
