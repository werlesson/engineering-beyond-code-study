<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RawFixtures;
use Tests\TestCase;

class ProfessionalModelTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    private const FOREIGN_KEY_VIOLATION = '23503';

    public function test_offer_attaches_service_with_company_id(): void
    {
        $company = Company::create(['name' => 'Company']);
        $professional = Professional::create(['company_id' => $company->id, 'name' => 'Ana']);
        $service = Service::create(['company_id' => $company->id, 'name' => 'Corte', 'duration_minutes' => 30]);

        $professional->offer($service);

        $this->assertDatabaseHas('professional_service', [
            'company_id' => $company->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($professional->services()->whereKey($service->id)->exists());
        $this->assertSame($company->id, $professional->services()->first()->pivot->company_id);
    }

    public function test_offer_service_from_other_company_is_rejected_by_database(): void
    {
        $company = Company::create(['name' => 'Company']);
        $otherCompany = Company::create(['name' => 'Other']);
        $professional = Professional::create(['company_id' => $company->id, 'name' => 'Ana']);
        $foreignService = Service::create(['company_id' => $otherCompany->id, 'name' => 'Corte', 'duration_minutes' => 30]);

        $this->assertSqlState(self::FOREIGN_KEY_VIOLATION, fn () => $professional->offer($foreignService));

        $this->assertDatabaseCount('professional_service', 0);
    }
}
