<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Support\ProcessDatabaseGuard;
use Tests\TestCase;

/**
 * Guard dos processos filhos (worker e servidor HTTP de teste). Não usa RefreshDatabase.
 */
class ProcessDatabaseGuardTest extends TestCase
{
    private const SAFE_OUTPUT = '{"result":"guard passed"}';

    public function test_worker_guard_exits_with_unsafe_database_output_for_other_database(): void
    {
        $result = $this->runGuardedProcess(['DB_DATABASE' => 'agendamento']);

        $this->assertSame(0, $result->exitCode());
        $this->assertSame(ProcessDatabaseGuard::UNSAFE_DATABASE_OUTPUT, trim($result->output()));
        $this->assertSame(['result' => 'error', 'message' => 'unsafe database'], json_decode(trim($result->output()), true));
    }

    public function test_worker_guard_exits_with_unsafe_database_output_when_database_is_missing(): void
    {
        $result = $this->runGuardedProcess(['DB_DATABASE' => false]);

        $this->assertSame(0, $result->exitCode());
        $this->assertSame(ProcessDatabaseGuard::UNSAFE_DATABASE_OUTPUT, trim($result->output()));
    }

    public function test_worker_guard_lets_agendamento_testing_continue(): void
    {
        $result = $this->runGuardedProcess(['DB_DATABASE' => 'agendamento_testing']);

        $this->assertSame(0, $result->exitCode());
        $this->assertSame(self::SAFE_OUTPUT, trim($result->output()));
    }

    public function test_http_server_guard_rejects_environment_with_other_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ProcessDatabaseGuard::UNSAFE_DATABASE_OUTPUT);

        ProcessDatabaseGuard::assertSafeProcessEnvironment(['APP_ENV' => 'testing', 'DB_DATABASE' => 'agendamento']);
    }

    public function test_http_server_guard_rejects_environment_without_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ProcessDatabaseGuard::UNSAFE_DATABASE_OUTPUT);

        ProcessDatabaseGuard::assertSafeProcessEnvironment(['APP_ENV' => 'testing']);
    }

    public function test_http_server_guard_accepts_agendamento_testing(): void
    {
        ProcessDatabaseGuard::assertSafeProcessEnvironment(['APP_ENV' => 'testing', 'DB_DATABASE' => 'agendamento_testing']);

        $this->assertTrue(ProcessDatabaseGuard::isSafeDatabase('agendamento_testing'));
    }

    /**
     * Roda um processo PHP real que inclui o guard e, se passar, imprime SAFE_OUTPUT.
     *
     * @param  array<string, string|false>  $environment
     */
    private function runGuardedProcess(array $environment): ProcessResult
    {
        $guardScript = base_path('tests/Concurrency/guard_database.php');

        return Process::env($environment)
            ->timeout(30)
            ->run([PHP_BINARY, '-r', 'require '.var_export($guardScript, true).'; echo '.var_export(self::SAFE_OUTPUT, true).';']);
    }
}
