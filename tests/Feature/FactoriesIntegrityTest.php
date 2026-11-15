<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RawFixtures;
use Tests\TestCase;

/**
 * As factories geram dados válidos sob todas as constraints do banco.
 */
class FactoriesIntegrityTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    public function test_company_factory_generates_name(): void
    {
        $company = Company::factory()->create();

        $this->assertNotEmpty($company->name);
    }

    public function test_user_factory_creates_user_with_company(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(Company::class, $user->company);
        $this->assertTrue($user->company->users->contains($user));
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotEmpty($user->email);
    }

    public function test_professional_customer_and_service_factories_create_company(): void
    {
        $professional = Professional::factory()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();

        $this->assertDatabaseCount('companies', 3);
        $this->assertInstanceOf(Company::class, $professional->company);
        $this->assertInstanceOf(Company::class, $customer->company);
        $this->assertInstanceOf(Company::class, $service->company);
        $this->assertContains($service->duration_minutes, [30, 45, 60]);
    }

    public function test_service_factory_duration_state(): void
    {
        $service = Service::factory()->duration(90)->create();

        $this->assertSame(90, $service->fresh()->duration_minutes);
    }

    public function test_professional_factory_offering_state_attaches_services(): void
    {
        $company = Company::factory()->create();
        $services = Service::factory()->count(2)->for($company)->create();

        $professional = Professional::factory()->for($company)->offering(...$services)->create();

        $this->assertEqualsCanonicalizing($services->modelKeys(), $professional->services->modelKeys());
        $this->assertDatabaseHas('professional_service', [
            'company_id' => $company->id,
            'professional_id' => $professional->id,
            'service_id' => $services->first()->id,
        ]);
        $this->assertTrue($services->first()->professionals->contains($professional));
    }

    public function test_booking_factory_satisfies_all_constraints(): void
    {
        $bookings = Booking::factory()->count(10)->create();

        $this->assertDatabaseCount('bookings', 10);
        $this->assertCount(10, $bookings->pluck('professional_id')->unique());

        foreach ($bookings->load(['professional', 'customer', 'service']) as $booking) {
            $this->assertSame($booking->company_id, $booking->professional->company_id);
            $this->assertSame($booking->company_id, $booking->customer->company_id);
            $this->assertSame($booking->company_id, $booking->service->company_id);
            $this->assertTrue($booking->professional->services->contains($booking->service));
        }
    }

    public function test_booking_factory_computes_ends_at_from_service_duration(): void
    {
        $this->freezeClock();

        $booking = Booking::factory()->create()->fresh();

        $this->assertSame(BookingStatus::Confirmed, $booking->status_id);
        $this->assertSame('2026-01-02T14:00:00+00:00', $booking->starts_at->toAtomString());
        $this->assertSame(
            $booking->service->duration_minutes,
            (int) $booking->starts_at->diffInMinutes($booking->ends_at),
        );
    }

    public function test_booking_factory_cancelled_state(): void
    {
        $booking = Booking::factory()->cancelled()->create()->fresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status_id);
    }

    public function test_booking_factory_for_state_uses_given_models(): void
    {
        $company = Company::factory()->create();
        $service = Service::factory()->for($company)->duration(45)->create();
        $professional = Professional::factory()->for($company)->offering($service)->create();
        $customer = Customer::factory()->for($company)->create();

        $booking = Booking::factory()->for($professional, $service, $customer)->create();

        $this->assertSame($company->id, $booking->company_id);
        $this->assertSame($professional->id, $booking->professional_id);
        $this->assertSame($service->id, $booking->service_id);
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame(45, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_booking_factory_for_state_with_service_not_offered_is_rejected(): void
    {
        $company = Company::factory()->create();
        $service = Service::factory()->for($company)->create();
        $professional = Professional::factory()->for($company)->create();
        $customer = Customer::factory()->for($company)->create();

        $this->assertSqlState('23503', fn () => Booking::factory()->for($professional, $service, $customer)->create());
    }

    public function test_booking_factory_starting_at_recalculates_ends_at(): void
    {
        $startsAt = CarbonImmutable::parse('2026-03-10 09:30:00', 'America/Sao_Paulo');
        $service = Service::factory()->duration(60)->create();
        $professional = Professional::factory()->for($service->company)->offering($service)->create();
        $customer = Customer::factory()->for($service->company)->create();

        $booking = Booking::factory()
            ->for($professional, $service, $customer)
            ->startingAt($startsAt)
            ->create()
            ->fresh();

        $this->assertSame('2026-03-10T12:30:00+00:00', $booking->starts_at->toAtomString());
        $this->assertSame('2026-03-10T13:30:00+00:00', $booking->ends_at->toAtomString());
    }
}
