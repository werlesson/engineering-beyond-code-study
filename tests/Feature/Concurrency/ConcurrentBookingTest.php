<?php

namespace Tests\Feature\Concurrency;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Connection;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\InvokedProcessPool;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ConcurrencyScenario;
use Tests\TestCase;

/**
 * Concorrência real na camada de negócio: 5 processos PHP chamam a CreateBooking com o
 * mesmo intervalo, liberados ao mesmo tempo por um advisory lock usado como barreira.
 *
 * Orçamento de tempo: barreira ≤ 10 s (aqui) < 15 s de lock_timeout no worker; insert ≤ 5 s;
 * boot do worker ≈ 1–2 s; total < 30 s de timeout por processo.
 */
#[Group('concurrency')]
class ConcurrentBookingTest extends TestCase
{
    use ConcurrencyScenario;

    private const WORKERS = 5;

    private const PROCESS_TIMEOUT_SECONDS = 30;

    private const BARRIER_TIMEOUT_SECONDS = 10;

    private const BARRIER_POLL_MICROSECONDS = 50_000;

    private const ORCHESTRATOR_CONNECTION = 'pgsql_orchestrator';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyScenario();
    }

    protected function tearDown(): void
    {
        $this->tearDownConcurrencyScenario();
        DB::purge(self::ORCHESTRATOR_CONNECTION);

        parent::tearDown();
    }

    public function test_only_one_of_five_simultaneous_overlapping_bookings_is_confirmed(): void
    {
        $startsAt = CarbonImmutable::now()->utc()->addDays(30)->setTime(14, 0);
        $lockKey = random_int(1, 2_147_483_647);

        $results = $this->runWorkersReleasedTogether($lockKey, [
            'company_id' => $this->company->getKey(),
            'professional_id' => $this->professional->getKey(),
            'customer_id' => $this->customer->getKey(),
            'service_id' => $this->service->getKey(),
            'starts_at' => $startsAt->toIso8601String(),
            'lock_key' => $lockKey,
        ]);

        $outcomes = ['created' => 0, 'conflict' => 0, 'error' => 0];
        $errors = [];

        foreach ($results as $key => $result) {
            $this->assertSame(0, $result->exitCode(), "process: worker [{$key}] exited with code {$result->exitCode()}: {$result->errorOutput()}");

            $output = json_decode(trim($result->output()), true);
            $this->assertIsArray($output, "process: worker [{$key}] printed invalid JSON: {$result->output()} {$result->errorOutput()}");
            $this->assertArrayHasKey($output['result'] ?? null, $outcomes, "process: worker [{$key}] printed an unknown result: {$result->output()}");

            $outcomes[$output['result']]++;

            if ($output['result'] === 'error') {
                $errors[] = "[{$key}] ".($output['message'] ?? '');
            }
        }

        $this->assertSame(
            ['created' => 1, 'conflict' => self::WORKERS - 1, 'error' => 0],
            $outcomes,
            'Worker errors: '.implode(' | ', $errors),
        );

        $this->assertSame(1, Booking::confirmed()
            ->where('professional_id', $this->professional->getKey())
            ->where('starts_at', $startsAt->toIso8601String())
            ->where('ends_at', $startsAt->addMinutes(60)->toIso8601String())
            ->count());
    }

    /**
     * Segura o lock exclusivo, dispara os workers sem bloquear, espera todos pararem na
     * barreira, libera o lock e só então coleta os resultados.
     *
     * @param  array<string, int|string>  $input
     * @return array<string, ProcessResult>
     */
    private function runWorkersReleasedTogether(int $lockKey, array $input): array
    {
        $orchestrator = $this->orchestratorConnection();
        $orchestrator->select('SELECT pg_advisory_lock(?)', [$lockKey]);

        $pool = null;
        $released = false;

        try {
            $pool = $this->startWorkers($input);
            $this->waitForWorkersAtBarrier($orchestrator, $lockKey);
            $released = true;
        } finally {
            if (! $released && $pool !== null) {
                $this->terminate($pool);
            }

            $orchestrator->select('SELECT pg_advisory_unlock(?)', [$lockKey]);
        }

        try {
            return $pool->wait()->collect()->all();
        } catch (ProcessTimedOutException $exception) {
            $this->terminate($pool);
            $this->fail('process: a worker exceeded the '.self::PROCESS_TIMEOUT_SECONDS.'s process timeout: '.$exception->getMessage());
        }
    }

    /**
     * Process::pool(...)->start() é assíncrono: retorna sem esperar os workers.
     *
     * @param  array<string, int|string>  $input
     */
    private function startWorkers(array $input): InvokedProcessPool
    {
        $command = [PHP_BINARY, base_path('tests/Concurrency/create_booking_worker.php'), json_encode($input)];
        $environment = $this->childProcessEnvironment();

        return Process::pool(function (Pool $pool) use ($command, $environment): void {
            for ($worker = 1; $worker <= self::WORKERS; $worker++) {
                $pool->as("worker-{$worker}")
                    ->path(base_path())
                    ->timeout(self::PROCESS_TIMEOUT_SECONDS)
                    ->env($environment)
                    ->command($command);
            }
        })->start();
    }

    /**
     * Espera até todos os workers estarem bloqueados no lock compartilhado da barreira.
     */
    private function waitForWorkersAtBarrier(Connection $orchestrator, int $lockKey): void
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;

        do {
            $waiting = (int) $orchestrator->scalar(
                "SELECT count(*) FROM pg_locks
                 WHERE locktype = 'advisory' AND granted = false
                   AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
                   AND classid::bigint = 0 AND objid::bigint = ? AND objsubid = 1",
                [$lockKey],
            );

            if ($waiting === self::WORKERS) {
                return;
            }

            usleep(self::BARRIER_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        $this->fail('barrier: only '.$waiting.' of '.self::WORKERS.' workers reached the barrier within '.self::BARRIER_TIMEOUT_SECONDS.'s.');
    }

    /**
     * Conexão dedicada, clonada em runtime da config pgsql, que segura o lock exclusivo.
     */
    private function orchestratorConnection(): Connection
    {
        config(['database.connections.'.self::ORCHESTRATOR_CONNECTION => config('database.connections.pgsql')]);

        return DB::connection(self::ORCHESTRATOR_CONNECTION);
    }

    /**
     * Encerra os workers ainda vivos, para nunca deixar processos presos.
     */
    private function terminate(InvokedProcessPool $pool): void
    {
        $pool->running()->each(function (InvokedProcess $process): void {
            try {
                $process->signal(SIGTERM);
            } catch (LogicException) {
                // O processo terminou entre running() e signal().
            }
        });
    }
}
