<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RawFixtures;
use Tests\TestCase;

class BookingModelTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    public function test_status_is_cast_to_enum(): void
    {
        $booking = Booking::findOrFail($this->tomorrowBooking());

        $this->assertSame(BookingStatus::Confirmed, $booking->status_id);
    }

    public function test_relationships_resolve_to_same_company(): void
    {
        $booking = Booking::with(['company', 'professional', 'customer', 'service'])->findOrFail($this->tomorrowBooking());

        $this->assertSame($booking->company_id, $booking->company->id);
        $this->assertSame($booking->company_id, $booking->professional->company_id);
        $this->assertSame($booking->company_id, $booking->customer->company_id);
        $this->assertSame($booking->company_id, $booking->service->company_id);
    }

    public function test_dates_read_from_database_are_utc(): void
    {
        $this->freezeClock();
        $startsAt = CarbonImmutable::parse('2026-01-02 11:00:00', 'America/Sao_Paulo');

        $booking = Booking::create($this->bookableOffer() + [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);

        $fresh = $booking->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $fresh->starts_at);
        $this->assertSame('UTC', $fresh->starts_at->timezoneName);
        $this->assertSame('2026-01-02T14:00:00+00:00', $fresh->starts_at->toAtomString());
        $this->assertSame('2026-01-02T15:00:00+00:00', $fresh->ends_at->toAtomString());
    }

    public function test_confirmed_scope_excludes_cancelled_bookings(): void
    {
        $confirmedId = $this->tomorrowBooking();
        $this->tomorrowBooking(BookingStatus::Cancelled);

        $this->assertSame([$confirmedId], Booking::confirmed()->pluck('id')->all());
    }

    private function tomorrowBooking(BookingStatus $status = BookingStatus::Confirmed): int
    {
        $startsAt = now()->addDay()->setTime(14, 0);

        return $this->booking($this->bookableOffer() + [
            'status_id' => $status->value,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);
    }
}
