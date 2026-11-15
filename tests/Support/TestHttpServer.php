<?php

namespace Tests\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Servidor HTTP real (`php artisan serve`, multi-worker) para os testes de concorrência ponta a ponta.
 *
 * O processo recebe o banco de testes pelo ambiente. stop() deve ser chamado num finally ou no
 * tearDown para nunca deixar o servidor órfão.
 */
final class TestHttpServer
{
    private const HOST = '127.0.0.1';

    /**
     * Workers do servidor embutido do PHP, para atender requisições em paralelo.
     */
    private const WORKERS = 5;

    private const READY_TIMEOUT_SECONDS = 10;

    private const READY_POLL_MICROSECONDS = 100_000;

    private const STOP_TIMEOUT_SECONDS = 5;

    private ?InvokedProcess $process = null;

    private ?int $port = null;

    /**
     * @param  array<string, string>  $databaseEnvironment  Variáveis DB_* repassadas ao servidor.
     */
    public function __construct(private readonly array $databaseEnvironment) {}

    /**
     * Sobe o servidor numa porta livre e espera GET /up responder 200.
     */
    public function start(): void
    {
        TestCase::assertSafeTestDatabase();

        if ($this->process !== null) {
            throw new RuntimeException('The test HTTP server is already running.');
        }

        $environment = array_merge($this->databaseEnvironment, [
            'APP_ENV' => 'testing',
            'PHP_CLI_SERVER_WORKERS' => (string) self::WORKERS,
            'DB_DATABASE' => TestCase::TEST_DATABASE,
        ]);

        ProcessDatabaseGuard::assertSafeProcessEnvironment($environment);

        $this->port = $this->freePort();
        $this->process = Process::path(base_path())
            ->env($environment)
            ->forever()
            ->start([PHP_BINARY, 'artisan', 'serve', '--host='.self::HOST, '--port='.$this->port, '--no-reload']);

        $this->waitUntilReady();
    }

    /**
     * Encerra o servidor e toda a árvore de processos dele.
     *
     * O artisan serve inicia o servidor embutido (`php -S`), que cria os workers. Encerrar só o
     * artisan serve pode deixar o `php -S` e os workers órfãos, então os descendentes são
     * coletados antes e encerrados depois: SIGTERM e, se ainda vivos, SIGKILL.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $process = $this->process;
        $this->process = null;

        $rootPid = $process->id();
        $descendants = $rootPid === null ? [] : $this->descendantPids($rootPid);

        if ($process->running()) {
            $process->stop(self::STOP_TIMEOUT_SECONDS, SIGTERM);
        }

        $this->terminateAll($descendants);
    }

    public function url(string $path): string
    {
        if ($this->port === null) {
            throw new RuntimeException('The test HTTP server has not been started.');
        }

        return 'http://'.self::HOST.':'.$this->port.'/'.ltrim($path, '/');
    }

    /**
     * PIDs de todos os descendentes do processo, lidos de /proc (filhos antes dos netos).
     *
     * @return list<int>
     */
    private function descendantPids(int $rootPid): array
    {
        $parents = [];

        foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);

            if ($stat === false) {
                continue;
            }

            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            $parents[(int) basename(dirname($statFile))] = (int) $fields[1];
        }

        $descendants = [];
        $queue = [$rootPid];

        while ($queue !== []) {
            $parent = array_shift($queue);

            foreach ($parents as $pid => $parentPid) {
                if ($parentPid === $parent && ! in_array($pid, $descendants, true)) {
                    $descendants[] = $pid;
                    $queue[] = $pid;
                }
            }
        }

        return $descendants;
    }

    /**
     * Envia SIGTERM aos processos e SIGKILL aos que não terminarem a tempo.
     *
     * @param  list<int>  $pids
     */
    private function terminateAll(array $pids): void
    {
        $alive = fn (): array => array_values(array_filter($pids, fn (int $pid): bool => posix_kill($pid, 0)));

        foreach ($alive() as $pid) {
            posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + self::STOP_TIMEOUT_SECONDS;

        while ($alive() !== [] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        foreach ($alive() as $pid) {
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * Abre um socket na porta 0 para o sistema escolher uma porta livre, lê a porta e fecha o socket.
     */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://'.self::HOST.':0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("Unable to find a free port: {$errorMessage} ({$errorCode}).");
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;

        do {
            if (! $this->process->running()) {
                $output = $this->process->output().$this->process->errorOutput();
                $this->stop();
                Assert::fail("server: the test HTTP server exited before becoming ready: {$output}");
            }

            try {
                if (Http::timeout(1)->get($this->url('/up'))->status() === 200) {
                    return;
                }
            } catch (Throwable) {
                // Servidor ainda não aceita conexões.
            }

            usleep(self::READY_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        $this->stop();
        Assert::fail('server: GET /up did not return 200 within '.self::READY_TIMEOUT_SECONDS.'s.');
    }
}
