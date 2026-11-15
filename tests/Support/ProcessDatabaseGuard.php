<?php

namespace Tests\Support;

use RuntimeException;
use Tests\TestCase;

/**
 * Guard dos processos filhos dos testes (worker de concorrência e servidor HTTP de teste).
 *
 * Esses processos não passam pelo TestCase, então conferem o DB_DATABASE recebido
 * pelo ambiente antes de qualquer escrita.
 */
final class ProcessDatabaseGuard
{
    /**
     * Saída impressa pelo processo quando o banco não é o de testes.
     */
    public const UNSAFE_DATABASE_OUTPUT = '{"result":"error","message":"unsafe database"}';

    public static function isSafeDatabase(?string $database): bool
    {
        return $database === TestCase::TEST_DATABASE;
    }

    /**
     * Lê o DB_DATABASE do ambiente do processo atual.
     */
    public static function currentProcessDatabase(): ?string
    {
        $database = getenv('DB_DATABASE');

        if ($database === false) {
            $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null;
        }

        return is_string($database) ? $database : null;
    }

    /**
     * Usado pelo worker antes do bootstrap: se o banco não for o de testes, imprime
     * a saída de erro e encerra o processo com exit code 0.
     */
    public static function guardCurrentProcess(): void
    {
        if (self::isSafeDatabase(self::currentProcessDatabase())) {
            return;
        }

        fwrite(STDOUT, self::UNSAFE_DATABASE_OUTPUT.PHP_EOL);

        exit(0);
    }

    /**
     * Usado pelo helper do servidor HTTP antes de iniciar o processo com o env informado.
     *
     * @param  array<string, string|int|bool|null>  $environment
     *
     * @throws RuntimeException
     */
    public static function assertSafeProcessEnvironment(array $environment): void
    {
        $database = $environment['DB_DATABASE'] ?? null;

        if (! is_string($database) || ! self::isSafeDatabase($database)) {
            throw new RuntimeException(self::UNSAFE_DATABASE_OUTPUT);
        }
    }
}
