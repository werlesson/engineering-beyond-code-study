<?php

namespace Tests\Feature;

use App\Actions\CreateBooking;
use App\Data\CreateBookingData;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BookingScenario;
use Tests\TestCase;

class CreateBookingActionTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_creates_confirmed_booking_with_computed_ends_at(): void
    {
        $scenario = $this->bookableScenario(durationMinutes: 45);
        $startsAt = now()->toImmutable()->addDay()->setTime(14, 0);

        $booking = app(CreateBooking::class)->handle(new CreateBookingData(
            companyId: $scenario['company']->getKey(),
            professionalId: $scenario['professional']->getKey(),
            customerId: $scenario['customer']->getKey(),
            serviceId: $scenario['service']->getKey(),
            startsAt: $startsAt,
        ));

        $booking = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status_id);
        $this->assertTrue($booking->starts_at->equalTo($startsAt));
        $this->assertTrue($booking->ends_at->equalTo($startsAt->addMinutes(45)));
    }

    public function test_uses_company_from_data_not_from_elsewhere(): void
    {
        $scenario = $this->bookableScenario();
        $otherCompany = $this->bookableScenario();
        Sanctum::actingAs($otherCompany['user']);

        $booking = app(CreateBooking::class)->handle(new CreateBookingData(
            companyId: $scenario['company']->getKey(),
            professionalId: $scenario['professional']->getKey(),
            customerId: $scenario['customer']->getKey(),
            serviceId: $scenario['service']->getKey(),
            startsAt: CarbonImmutable::now()->addDay(),
        ));

        $this->assertNotSame($scenario['company']->getKey(), Auth::user()->company_id);
        $this->assertSame($scenario['company']->getKey(), $booking->fresh()->company_id);
        $this->assertSame(1, Booking::where('company_id', $scenario['company']->getKey())->count());
    }
}
