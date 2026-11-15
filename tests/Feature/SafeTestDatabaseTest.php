<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Não usa RefreshDatabase: o guard é exercitado sem rodar migrate:fresh.
 */
class SafeTestDatabaseTest extends TestCase
{
    public function test_guard_rejects_non_testing_database(): void
    {
        $this->withDatabase('agendamento', function (): void {
            $this->expectException(RuntimeException::class);

            TestCase::assertSafeTestDatabase();
        });
    }

    public function test_guard_accepts_agendamento_testing(): void
    {
        TestCase::assertSafeTestDatabase();

        $this->assertSame('agendamento_testing', DB::connection()->getDatabaseName());
    }

    public function test_guard_rejects_non_pgsql_driver(): void
    {
        $original = config('database.default');

        try {
            config(['database.default' => 'sqlite']);
            DB::purge();

            $this->expectException(RuntimeException::class);

            TestCase::assertSafeTestDatabase();
        } finally {
            config(['database.default' => $original]);
            DB::purge();
        }
    }

    public function test_guard_rejects_non_testing_environment(): void
    {
        $original = $this->app['env'];

        try {
            $this->app['env'] = 'local';

            $this->expectException(RuntimeException::class);

            TestCase::assertSafeTestDatabase();
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_before_refreshing_database_hook_runs_guard(): void
    {
        $this->withDatabase('agendamento_guard_probe', function (): void {
            $this->expectException(RuntimeException::class);

            $this->beforeRefreshingDatabase();
        });
    }

    public function test_guard_runs_for_tests_using_refresh_database(): void
    {
        // Banco inexistente: se o guard falhasse, o migrate:fresh erraria a conexão em vez de apagar dados.
        $this->withDatabase('agendamento_guard_probe', function (): void {
            $refreshingTest = new class('probe') extends TestCase
            {
                use RefreshDatabase;
            };

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe test database');

            (new ReflectionMethod($refreshingTest, 'setUpTraits'))->invoke($refreshingTest);
        });
    }

    /**
     * Executa o callback com a conexão pgsql apontando para outro banco e restaura a config ao final.
     */
    private function withDatabase(string $database, Closure $callback): void
    {
        $original = config('database.connections.pgsql.database');

        try {
            config(['database.connections.pgsql.database' => $database]);
            DB::purge();

            $callback();
        } finally {
            config(['database.connections.pgsql.database' => $original]);
            DB::purge();
        }
    }
}
