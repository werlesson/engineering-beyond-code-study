<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;

/**
 * Isolamento dos testes de concorrência real (grupo `concurrency`).
 *
 * Esses testes não usam RefreshDatabase nem DatabaseMigrations: os dados precisam de commit
 * real para serem vistos por outros processos. Cada execução cria uma empresa exclusiva
 * `concurrency-<uuid>` e a apaga no tearDown, sempre depois do guard do banco de testes.
 */
trait ConcurrencyScenario
{
    /**
     * Prefixo do nome das empresas criadas pelos testes de concorrência.
     */
    private const COMPANY_PREFIX = 'concurrency-';

    protected ?Company $company = null;

    protected User $user;

    protected Professional $professional;

    protected Customer $customer;

    protected Service $service;

    /**
     * Garante o schema, remove sobras de execuções anteriores e cria, com commit real,
     * a empresa exclusiva com user, professional, customer, serviço de 60 min e o vínculo.
     */
    protected function setUpConcurrencyScenario(): void
    {
        if (ParallelTesting::token() !== false) {
            $this->fail('Concurrency tests must never run with --parallel: they commit real data and spawn their own processes. Run them with `php artisan test --group=concurrency`.');
        }

        static::assertSafeTestDatabase();

        $this->artisan('migrate')->assertSuccessful()->run();

        $this->deleteConcurrencyCompanies(
            Company::where('name', 'like', self::COMPANY_PREFIX.'%')->pluck('id')->all(),
        );

        $this->company = Company::factory()->create(['name' => self::COMPANY_PREFIX.Str::uuid()]);
        $this->service = Service::factory()->for($this->company)->duration(60)->create();
        $this->user = User::factory()->for($this->company)->create();
        $this->professional = Professional::factory()->for($this->company)->offering($this->service)->create();
        $this->customer = Customer::factory()->for($this->company)->create();
    }

    /**
     * Apaga, com commit, tudo o que a empresa exclusiva desta execução criou.
     */
    protected function tearDownConcurrencyScenario(): void
    {
        static::assertSafeTestDatabase();

        if ($this->company !== null) {
            $this->deleteConcurrencyCompanies([$this->company->getKey()]);
            $this->company = null;
        }
    }

    /**
     * Apaga, nesta ordem, bookings, pivô, services, customers, professionals, tokens, users e companies.
     *
     * @param  list<int>  $companyIds
     */
    private function deleteConcurrencyCompanies(array $companyIds): void
    {
        static::assertSafeTestDatabase();

        if ($companyIds === []) {
            return;
        }

        DB::transaction(function () use ($companyIds): void {
            $userIds = DB::table('users')->whereIn('company_id', $companyIds)->pluck('id')->all();

            DB::table('bookings')->whereIn('company_id', $companyIds)->delete();
            DB::table('professional_service')->whereIn('company_id', $companyIds)->delete();
            DB::table('services')->whereIn('company_id', $companyIds)->delete();
            DB::table('customers')->whereIn('company_id', $companyIds)->delete();
            DB::table('professionals')->whereIn('company_id', $companyIds)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $userIds)
                ->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('companies')->whereIn('id', $companyIds)->delete();
        });
    }

    /**
     * Variáveis de ambiente dos processos filhos: banco de testes e demais DB_* da conexão pgsql.
     *
     * @return array<string, string>
     */
    protected function childProcessEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => static::TEST_DATABASE,
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
        ];
    }
}
