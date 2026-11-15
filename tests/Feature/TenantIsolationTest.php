<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * O usuário da empresa A não consegue referenciar registros da empresa B: a tentativa é
 * indistinguível de um ID inexistente e nada é persistido.
 */
class TenantIsolationTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    /**
     * Empresa do usuário autenticado.
     *
     * @var array{company: Company, user: User, professional: Professional, customer: Customer, service: Service}
     */
    private array $companyA;

    /**
     * Outra empresa, com registros que o usuário não pode referenciar.
     *
     * @var array{company: Company, user: User, professional: Professional, customer: Customer, service: Service}
     */
    private array $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        $this->companyA = $this->bookableScenario();
        $this->companyB = $this->bookableScenario();
        Sanctum::actingAs($this->companyA['user']);
    }

    public function test_professional_from_other_company_returns_422_on_professional_id(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
            'professional_id' => $this->companyB['professional']->getKey(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('professional_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_from_other_company_returns_422_on_customer_id(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
            'customer_id' => $this->companyB['customer']->getKey(),
        ]))->assertUnprocessable()->assertOnlyJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_service_from_other_company_returns_422_on_service_id(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
            'service_id' => $this->companyB['service']->getKey(),
        ]))->assertUnprocessable()->assertOnlyJsonValidationErrors('service_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_cross_tenant_error_message_equals_nonexistent_id_message(): void
    {
        foreach (['professional', 'customer', 'service'] as $resource) {
            $field = "{$resource}_id";

            $crossTenantErrors = $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
                $field => $this->companyB[$resource]->getKey(),
            ]))->assertUnprocessable()->json("errors.{$field}");

            $nonexistentErrors = $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
                $field => PHP_INT_MAX,
            ]))->assertUnprocessable()->json("errors.{$field}");

            $this->assertNotEmpty($crossTenantErrors, "Expected errors on [{$field}].");
            $this->assertSame($nonexistentErrors, $crossTenantErrors, "Cross-tenant error on [{$field}] reveals the record.");
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_service_not_offered_by_professional_returns_422_on_service_id(): void
    {
        $notOfferedService = Service::factory()->for($this->companyA['company'])->create();

        $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
            'service_id' => $notOfferedService->getKey(),
        ]))->assertUnprocessable()->assertOnlyJsonValidationErrors('service_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_company_id_in_payload_is_ignored(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->companyA, [
            'company_id' => $this->companyB['company']->getKey(),
        ]))->assertCreated();

        $this->assertSame($this->companyA['company']->getKey(), Booking::sole()->company_id);
    }
}
