<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RawFixtures;
use Tests\TestCase;

/**
 * Isolamento multi-tenant imposto pelas FKs compostas, provado com inserts diretos.
 */
class TenantIntegrityTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    private const FOREIGN_KEY_VIOLATION = '23503';

    /**
     * SQLSTATE do PostgreSQL para FKs declaradas com ON DELETE RESTRICT (NO ACTION daria 23503).
     */
    private const RESTRICT_VIOLATION = '23001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_booking_with_customer_from_other_company_fails(): void
    {
        $offer = $this->bookableOffer();
        $otherCompanyCustomerId = $this->customer($this->company('Other'));

        $this->assertBookingRejected(['customer_id' => $otherCompanyCustomerId] + $offer);
    }

    public function test_booking_with_professional_from_other_company_fails(): void
    {
        $offer = $this->bookableOffer();
        $otherCompanyId = $this->company('Other');
        $otherProfessionalId = $this->professional($otherCompanyId);
        $otherServiceId = $this->service($otherCompanyId);
        $this->offer($otherCompanyId, $otherProfessionalId, $otherServiceId);

        $this->assertBookingRejected(['professional_id' => $otherProfessionalId] + $offer);
    }

    public function test_booking_with_service_from_other_company_fails(): void
    {
        $offer = $this->bookableOffer();
        $otherCompanyId = $this->company('Other');
        $otherProfessionalId = $this->professional($otherCompanyId);
        $otherServiceId = $this->service($otherCompanyId);
        $this->offer($otherCompanyId, $otherProfessionalId, $otherServiceId);

        $this->assertBookingRejected(['service_id' => $otherServiceId] + $offer);
    }

    public function test_booking_with_service_not_offered_by_professional_fails(): void
    {
        $offer = $this->bookableOffer();
        $notOfferedServiceId = $this->service($offer['company_id'], 30, 'Not offered');

        $this->assertDatabaseMissing('professional_service', [
            'professional_id' => $offer['professional_id'],
            'service_id' => $notOfferedServiceId,
        ]);

        $this->assertBookingRejected(['service_id' => $notOfferedServiceId] + $offer);
    }

    public function test_pivot_with_professional_and_service_from_different_companies_fails(): void
    {
        $companyId = $this->company();
        $professionalId = $this->professional($companyId);
        $otherCompanyServiceId = $this->service($this->company('Other'));

        $this->assertSqlState(
            self::FOREIGN_KEY_VIOLATION,
            fn () => $this->offer($companyId, $professionalId, $otherCompanyServiceId),
        );

        $this->assertDatabaseCount('professional_service', 0);
    }

    public function test_deleting_customer_with_bookings_is_restricted(): void
    {
        $offer = $this->bookableOffer();
        $this->booking($offer + $this->oneHourFromTomorrow());

        $this->assertSqlState(
            self::RESTRICT_VIOLATION,
            fn () => DB::table('customers')->where('id', $offer['customer_id'])->delete(),
        );

        $this->assertDatabaseHas('customers', ['id' => $offer['customer_id']]);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_deleting_professional_cascades_pivot_rows(): void
    {
        $offer = $this->bookableOffer();
        $secondServiceId = $this->service($offer['company_id'], 30, 'Second');
        $this->offer($offer['company_id'], $offer['professional_id'], $secondServiceId);

        $this->assertDatabaseCount('professional_service', 2);

        DB::table('professionals')->where('id', $offer['professional_id'])->delete();

        $this->assertDatabaseMissing('professional_service', ['professional_id' => $offer['professional_id']]);
        $this->assertDatabaseCount('professional_service', 0);
        $this->assertDatabaseCount('services', 2);
    }

    /**
     * @param  array<string, int>  $attributes
     */
    private function assertBookingRejected(array $attributes): void
    {
        $this->assertSqlState(
            self::FOREIGN_KEY_VIOLATION,
            fn () => $this->booking($attributes + $this->oneHourFromTomorrow()),
        );

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array{starts_at: Carbon, ends_at: Carbon}
     */
    private function oneHourFromTomorrow(): array
    {
        return [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ];
    }
}
