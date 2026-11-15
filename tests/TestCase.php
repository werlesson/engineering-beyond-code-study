<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Instante fixo usado como "agora" nos testes que dependem do relógio.
     */
    public const FROZEN_NOW = '2026-01-01T12:00:00Z';

    /**
     * Único banco em que os testes podem escrever.
     */
    public const TEST_DATABASE = 'agendamento_testing';

    /**
     * Traits que apagam ou recriam o banco e, portanto, exigem o guard.
     *
     * @var list<class-string>
     */
    private const DESTRUCTIVE_DATABASE_TRAITS = [
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
    ];

    /**
     * Congela o relógio em FROZEN_NOW; datas de teste devem ser relativas a now().
     */
    protected function freezeClock(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * Garante que o ambiente é de testes e a conexão padrão aponta para o PostgreSQL de testes.
     *
     * @throws RuntimeException
     */
    public static function assertSafeTestDatabase(): void
    {
        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing') {
            throw new RuntimeException("Unsafe test database: environment is [{$environment}], expected [testing].");
        }

        if ($driver !== 'pgsql') {
            throw new RuntimeException("Unsafe test database: driver is [{$driver}], expected [pgsql].");
        }

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException("Unsafe test database: database is [{$database}], expected [".self::TEST_DATABASE.'].');
        }
    }

    /**
     * Hook do RefreshDatabase executado antes de qualquer migrate:fresh.
     *
     * Sem tipo de retorno para continuar compatível com a assinatura do trait.
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        static::assertSafeTestDatabase();
    }

    /**
     * Um trait usado na classe filha sobrescreve o beforeRefreshingDatabase() herdado.
     * Por isso o guard também roda aqui, antes de qualquer trait que mexa no banco.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        if (array_intersect(self::DESTRUCTIVE_DATABASE_TRAITS, class_uses_recursive(static::class)) !== []) {
            static::assertSafeTestDatabase();
        }

        return parent::setUpTraits();
    }
}
