<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * Criação bem-sucedida via POST /api/bookings e cálculo de ends_at pela duração do serviço.
 */
class CreateBookingTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_valid_request_returns_201_with_resource(): void
    {
        $scenario = $this->bookableScenario();
        Sanctum::actingAs($scenario['user']);

        $response = $this->postJson(route('bookings.store'), $this->bookingPayload($scenario));

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'professional_id', 'customer_id', 'service_id', 'starts_at', 'ends_at', 'status', 'created_at',
            ]])
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.status_id')
            ->assertJsonPath('data.id', Booking::sole()->id)
            ->assertJsonPath('data.professional_id', $scenario['professional']->getKey())
            ->assertJsonPath('data.customer_id', $scenario['customer']->getKey())
            ->assertJsonPath('data.service_id', $scenario['service']->getKey())
            ->assertJsonPath('data.created_at', now()->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_booking_is_persisted_confirmed(): void
    {
        $scenario = $this->bookableScenario();
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('bookings', ['id' => Booking::sole()->id, 'status_id' => 1]);
    }

    public function test_dates_are_serialized_in_utc(): void
    {
        $scenario = $this->bookableScenario(durationMinutes: 60);
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario, [
            'starts_at' => '2026-01-02T14:00:00-03:00',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-01-02T17:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-01-02T18:00:00Z');
    }

    public function test_booking_belongs_to_authenticated_user_company(): void
    {
        $scenario = $this->bookableScenario();
        $this->bookableScenario();
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario))->assertCreated();

        $this->assertSame($scenario['user']->company_id, Booking::sole()->company_id);
    }

    public function test_ends_at_is_starts_at_plus_service_duration(): void
    {
        $scenario = $this->bookableScenario(durationMinutes: 45);
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario, [
            'starts_at' => '2026-01-02T14:00:00-03:00',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.ends_at', '2026-01-02T17:45:00Z');

        $this->assertSame('2026-01-02T17:45:00+00:00', Booking::sole()->ends_at->toAtomString());
    }

    public function test_ends_at_in_payload_is_ignored(): void
    {
        $scenario = $this->bookableScenario(durationMinutes: 30);
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario, [
            'starts_at' => '2026-01-02T14:00:00Z',
            'ends_at' => '2026-01-02T20:00:00Z',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.ends_at', '2026-01-02T14:30:00Z');

        $this->assertSame('2026-01-02T14:30:00+00:00', Booking::sole()->ends_at->toAtomString());
    }

    public function test_changing_service_duration_does_not_change_existing_bookings(): void
    {
        $scenario = $this->bookableScenario(durationMinutes: 60);
        Sanctum::actingAs($scenario['user']);

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario, [
            'starts_at' => '2026-01-02T14:00:00Z',
        ]))->assertCreated();
        $endsAtSnapshot = Booking::sole()->ends_at->toAtomString();

        $scenario['service']->update(['duration_minutes' => 90]);

        $this->assertSame('2026-01-02T15:00:00+00:00', $endsAtSnapshot);
        $this->assertSame($endsAtSnapshot, Booking::sole()->ends_at->toAtomString());
    }
}
