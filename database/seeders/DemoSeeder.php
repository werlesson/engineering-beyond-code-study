<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração para exercitar POST /api/bookings localmente.
 *
 * Só roda em ambiente local: cria duas empresas completas e emite um token Sanctum por usuário,
 * exibido em texto plano uma única vez no console (nunca em arquivo ou log).
 * Uso: php artisan migrate:fresh --seed
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Empresas de demonstração: nome e e-mail do usuário.
     *
     * @var array<string, string>
     */
    private const COMPANIES = [
        'Empresa A' => 'a@agendamento.test',
        'Empresa B' => 'b@agendamento.test',
    ];

    /**
     * Nome e validade dos tokens emitidos.
     */
    public const TOKEN_NAME = 'demo';

    public const TOKEN_TTL_DAYS = 7;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoSeeder ignorado: só roda em ambiente local (atual: '.app()->environment().').');

            return;
        }

        $tokenRows = [];
        $idRows = [];

        foreach (self::COMPANIES as $companyName => $email) {
            $dataset = $this->seedCompany($companyName, $email);

            $tokenRows[] = [$companyName, $email, $dataset['token']];
            $idRows[] = $this->describeIds($companyName, $dataset);
        }

        $this->command?->table(['Empresa', 'Usuário', 'Token'], $tokenRows);
        $this->command?->table(['Empresa', 'Profissionais', 'Clientes', 'Serviços', 'Vínculos'], $idRows);
    }

    /**
     * Cria a empresa com usuário, 2 profissionais, 2 clientes, 2 serviços (30 e 60 min) e os vínculos.
     *
     * O profissional 1 oferece os dois serviços; o profissional 2 só o de 30 min, deixando o par
     * (profissional 2, serviço de 60 min) sem vínculo para exercitar o 422.
     *
     * @return array{company: Company, token: string, professionals: list<Professional>, customers: list<Customer>, services: list<Service>}
     */
    private function seedCompany(string $companyName, string $email): array
    {
        $company = Company::factory()->create(['name' => $companyName]);

        $user = User::factory()->for($company)->create([
            'name' => "Usuário {$companyName}",
            'email' => $email,
            'password' => 'password',
        ]);

        $shortService = Service::factory()->for($company)->duration(30)->create(['name' => 'Serviço 30 min']);
        $longService = Service::factory()->for($company)->duration(60)->create(['name' => 'Serviço 60 min']);

        $firstProfessional = Professional::factory()->for($company)
            ->offering($shortService, $longService)
            ->create(['name' => "Profissional 1 ({$companyName})"]);
        $secondProfessional = Professional::factory()->for($company)
            ->offering($shortService)
            ->create(['name' => "Profissional 2 ({$companyName})"]);

        $customers = Customer::factory()->for($company)->count(2)->create();

        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addDays(self::TOKEN_TTL_DAYS));

        return [
            'company' => $company,
            'token' => $token->plainTextToken,
            'professionals' => [$firstProfessional, $secondProfessional],
            'customers' => $customers->all(),
            'services' => [$shortService, $longService],
        ];
    }

    /**
     * Linha da tabela de IDs da empresa, incluindo os vínculos profissional → serviços.
     *
     * @param  array{professionals: list<Professional>, customers: list<Customer>, services: list<Service>}  $dataset
     * @return list<string>
     */
    private function describeIds(string $companyName, array $dataset): array
    {
        $links = collect($dataset['professionals'])
            ->map(fn (Professional $professional): string => $professional->getKey().' → '
                .$professional->services()->orderBy('services.id')->pluck('services.id')->implode(', '))
            ->implode('; ');

        return [
            $companyName,
            collect($dataset['professionals'])->map->getKey()->implode(', '),
            collect($dataset['customers'])->map->getKey()->implode(', '),
            collect($dataset['services'])
                ->map(fn (Service $service): string => "{$service->getKey()} ({$service->duration_minutes} min)")
                ->implode(', '),
            $links,
        ];
    }
}
