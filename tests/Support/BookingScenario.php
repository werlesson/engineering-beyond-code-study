<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Monta, via factories, uma empresa pronta para agendar (usuário, profissional, cliente e
 * serviço oferecido pelo profissional) e o payload de POST /api/bookings correspondente.
 */
trait BookingScenario
{
    /**
     * @return array{company: Company, user: User, professional: Professional, customer: Customer, service: Service}
     */
    protected function bookableScenario(int $durationMinutes = 60): array
    {
        $company = Company::factory()->create();
        $service = Service::factory()->for($company)->duration($durationMinutes)->create();

        return [
            'company' => $company,
            'user' => User::factory()->for($company)->create(),
            'professional' => Professional::factory()->for($company)->offering($service)->create(),
            'customer' => Customer::factory()->for($company)->create(),
            'service' => $service,
        ];
    }

    /**
     * Payload válido para o cenário, começando amanhã às 14:00 UTC (relativo ao relógio congelado).
     *
     * @param  array{professional: Professional, customer: Customer, service: Service}  $scenario
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingPayload(array $scenario, array $overrides = []): array
    {
        return array_merge([
            'professional_id' => $scenario['professional']->getKey(),
            'customer_id' => $scenario['customer']->getKey(),
            'service_id' => $scenario['service']->getKey(),
            'starts_at' => CarbonImmutable::now()->utc()->addDay()->setTime(14, 0)->toIso8601ZuluString(),
        ], $overrides);
    }
}
