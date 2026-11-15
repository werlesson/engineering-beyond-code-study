<?php

namespace Tests\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Inserts diretos via query builder, sem modelos nem factories, para provar as
 * invariantes impostas pelo banco. Usar em testes com RefreshDatabase.
 */
trait RawFixtures
{
    /**
     * SQLSTATE de transação abortada: indica que um erro anterior não foi isolado num savepoint.
     */
    private const IN_FAILED_SQL_TRANSACTION = '25P02';

    protected function company(string $name = 'Company'): int
    {
        return DB::table('companies')->insertGetId($this->withTimestamps(['name' => $name]));
    }

    protected function professional(int $companyId, string $name = 'Professional'): int
    {
        return DB::table('professionals')->insertGetId($this->withTimestamps([
            'company_id' => $companyId,
            'name' => $name,
        ]));
    }

    protected function customer(int $companyId, string $name = 'Customer'): int
    {
        return DB::table('customers')->insertGetId($this->withTimestamps([
            'company_id' => $companyId,
            'name' => $name,
        ]));
    }

    protected function service(int $companyId, ?int $minutes = 60, ?string $name = 'Service'): int
    {
        return DB::table('services')->insertGetId($this->withTimestamps([
            'company_id' => $companyId,
            'name' => $name,
            'duration_minutes' => $minutes,
        ]));
    }

    /**
     * Vincula o serviço ao profissional na pivô professional_service.
     */
    protected function offer(int $companyId, int $professionalId, int $serviceId): void
    {
        DB::table('professional_service')->insert($this->withTimestamps([
            'company_id' => $companyId,
            'professional_id' => $professionalId,
            'service_id' => $serviceId,
        ]));
    }

    /**
     * Cria uma company com profissional, cliente e serviço vinculados, prontos para um booking válido.
     *
     * @return array{company_id: int, professional_id: int, customer_id: int, service_id: int}
     */
    protected function bookableOffer(): array
    {
        $companyId = $this->company();
        $professionalId = $this->professional($companyId);
        $serviceId = $this->service($companyId);
        $this->offer($companyId, $professionalId, $serviceId);

        return [
            'company_id' => $companyId,
            'professional_id' => $professionalId,
            'customer_id' => $this->customer($companyId),
            'service_id' => $serviceId,
        ];
    }

    /**
     * Insere um booking exatamente com os valores recebidos (inclusive inválidos).
     * Datas são enviadas em ISO 8601 com offset, para não depender do fuso da sessão.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function booking(array $attributes): int
    {
        $attributes = array_map(
            fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value,
            $attributes,
        );

        return DB::table('bookings')->insertGetId($this->withTimestamps($attributes));
    }

    /**
     * Executa a operação num savepoint e afirma que ela falha com o SQLSTATE esperado.
     *
     * O DB::transaction() aninhado na transação do RefreshDatabase vira SAVEPOINT; em caso
     * de erro o Laravel faz ROLLBACK TO SAVEPOINT antes de relançar a exceção, então a
     * transação externa continua utilizável depois desta asserção.
     */
    protected function assertSqlState(string $state, Closure $operation): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $actualState = $exception->errorInfo[0] ?? null;

            if ($actualState === self::IN_FAILED_SQL_TRANSACTION) {
                $this->fail('The operation ran inside an aborted transaction (SQLSTATE 25P02): '.$exception->getMessage());
            }

            $this->assertSame($state, $actualState, "Unexpected SQLSTATE: {$exception->getMessage()}");

            return;
        }

        $this->fail("Expected the operation to fail with SQLSTATE [{$state}], but no exception was thrown.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withTimestamps(array $attributes): array
    {
        $now = now();

        return $attributes + ['created_at' => $now, 'updated_at' => $now];
    }
}
