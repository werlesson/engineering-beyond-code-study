<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * DemoSeeder: guarda de ambiente, conjunto de dados por empresa, vínculos e tokens Sanctum.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Níveis de log PSR-3 mais o log() genérico.
     *
     * @var list<string>
     */
    private const LOG_METHODS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'];

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');

        parent::tearDown();
    }

    public function test_seeder_aborts_outside_local_environment(): void
    {
        $this->assertSame('testing', app()->environment());

        $output = $this->runDemoSeeder();

        $this->assertStringContainsString('DemoSeeder ignorado', $output);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_seeder_creates_two_companies_with_full_dataset(): void
    {
        $this->simulateLocalEnvironment();
        $this->runDemoSeeder();

        $companies = Company::query()->orderBy('name')->get();
        $this->assertSame(['Empresa A', 'Empresa B'], $companies->pluck('name')->all());

        foreach ($companies as $company) {
            $this->assertGreaterThanOrEqual(1, $company->users()->count());
            $this->assertSame(2, $company->professionals()->count());
            $this->assertSame(2, $company->customers()->count());

            $durations = $company->services()->pluck('duration_minutes');
            $this->assertCount(2, $durations);
            $this->assertCount(2, $durations->unique());
        }

        $this->assertDatabaseHas('users', ['email' => 'a@agendamento.test']);
        $this->assertDatabaseHas('users', ['email' => 'b@agendamento.test']);
    }

    public function test_seeder_leaves_at_least_one_unlinked_professional_service_pair(): void
    {
        $this->simulateLocalEnvironment();
        $this->runDemoSeeder();

        foreach (Company::all() as $company) {
            $unlinkedPairs = $company->professionals->flatMap(
                fn (Professional $professional) => $company->services
                    ->reject(fn (Service $service): bool => $professional->services()->whereKey($service->getKey())->exists())
                    ->map(fn (Service $service): array => [$professional->getKey(), $service->getKey()])
            );

            $this->assertCount(1, $unlinkedPairs, "Empresa {$company->name} deveria ter exatamente um par não vinculado.");
        }

        $this->assertSame(6, DB::table('professional_service')->count());
    }

    public function test_tokens_are_named_demo_and_expire_in_seven_days(): void
    {
        $this->freezeClock();
        $this->simulateLocalEnvironment();
        $this->runDemoSeeder();

        $tokens = PersonalAccessToken::all();

        $this->assertCount(2, $tokens);

        foreach ($tokens as $token) {
            $this->assertSame('demo', $token->name);
            $this->assertSame(['*'], $token->abilities);
            $this->assertTrue($token->expires_at->equalTo(now()->addDays(7)));
        }
    }

    public function test_printed_token_authenticates_booking_endpoint(): void
    {
        $this->simulateLocalEnvironment();
        $output = $this->runDemoSeeder();

        $plainTextTokens = $this->extractTokens($output);
        $this->assertCount(2, $plainTextTokens);

        $user = PersonalAccessToken::findToken($plainTextTokens[0])->tokenable;
        $company = $user->company;
        $professional = $company->professionals()->has('services')->firstOrFail();

        $this->withToken($plainTextTokens[0])
            ->postJson(route('bookings.store'), [
                'professional_id' => $professional->getKey(),
                'customer_id' => $company->customers()->firstOrFail()->getKey(),
                'service_id' => $professional->services()->firstOrFail()->getKey(),
                'starts_at' => CarbonImmutable::now()->utc()->addDay()->setTime(14, 0)->toIso8601ZuluString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('bookings', ['company_id' => $company->getKey()]);
    }

    public function test_tokens_are_not_logged(): void
    {
        Log::spy();
        $this->simulateLocalEnvironment();

        $plainTextTokens = $this->extractTokens($this->runDemoSeeder());
        $this->assertCount(2, $plainTextTokens);

        foreach ($plainTextTokens as $plainTextToken) {
            [, $secret] = explode('|', $plainTextToken, 2);

            foreach (self::LOG_METHODS as $method) {
                Log::shouldNotHaveReceived(
                    $method,
                    fn (mixed ...$arguments): bool => str_contains(json_encode($arguments) ?: '', $secret),
                );
            }
        }
    }

    /**
     * Simula o ambiente local; o tearDown restaura "testing".
     */
    private function simulateLocalEnvironment(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $this->assertTrue(app()->environment('local'));
    }

    /**
     * Roda o DemoSeeder pelo db:seed e devolve a saída do console.
     */
    private function runDemoSeeder(): string
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true]);

        return Artisan::output();
    }

    /**
     * Extrai da saída os tokens em texto plano ("{id}|{segredo}").
     *
     * @return list<string>
     */
    private function extractTokens(string $output): array
    {
        preg_match_all('/\b\d+\|[A-Za-z0-9]{40,}\b/', $output, $matches);

        return array_values(array_unique($matches[0]));
    }
}
