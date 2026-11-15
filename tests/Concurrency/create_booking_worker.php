<?php

/**
 * Worker do teste de concorrência real (Tests\Feature\Concurrency\ConcurrentBookingTest).
 *
 * Uso: php tests/Concurrency/create_booking_worker.php '<json>'
 *
 * O JSON traz company_id, professional_id, customer_id, service_id, starts_at (ISO 8601 com
 * offset, calculado pelo orquestrador) e lock_key (chave do advisory lock usado como barreira).
 *
 * Etapas:
 *  1. Guard: aborta antes do bootstrap se DB_DATABASE !== agendamento_testing.
 *  2. Barreira: lock_timeout de 15 s e pg_advisory_lock_shared(lock_key), que bloqueia até o
 *     orquestrador liberar o lock exclusivo.
 *  3. Insert: lock_timeout e statement_timeout de 5 s e CreateBooking::handle().
 *
 * Saída: uma única linha JSON — {"result":"created","id":N}, {"result":"conflict"} ou
 * {"result":"error","message":"..."} — e exit code sempre 0.
 */

use App\Actions\CreateBooking;
use App\Data\CreateBookingData;
use App\Exceptions\BookingConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/guard_database.php';

/**
 * @param  array<string, mixed>  $output
 */
$finish = static function (array $output): never {
    fwrite(STDOUT, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
};

$stage = 'boot';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = DB::connection();

    if ($connection->getDatabaseName() !== TestCase::TEST_DATABASE) {
        $finish(['result' => 'error', 'message' => 'unsafe database']);
    }

    /** @var array{company_id: int, professional_id: int, customer_id: int, service_id: int, starts_at: string, lock_key: int}|null $input */
    $input = json_decode($argv[1] ?? '', true);

    foreach (['company_id', 'professional_id', 'customer_id', 'service_id', 'starts_at', 'lock_key'] as $key) {
        if (! is_array($input) || ! array_key_exists($key, $input)) {
            $finish(['result' => 'error', 'message' => "boot: missing input [{$key}]"]);
        }
    }

    $lockKey = (int) $input['lock_key'];

    $stage = 'barrier';
    $connection->statement("SET lock_timeout = '15s'");
    $connection->select('SELECT pg_advisory_lock_shared(?)', [$lockKey]);

    try {
        $stage = 'insert';
        $connection->statement("SET lock_timeout = '5s'");
        $connection->statement("SET statement_timeout = '5s'");

        $booking = app(CreateBooking::class)->handle(new CreateBookingData(
            companyId: (int) $input['company_id'],
            professionalId: (int) $input['professional_id'],
            customerId: (int) $input['customer_id'],
            serviceId: (int) $input['service_id'],
            startsAt: CarbonImmutable::parse($input['starts_at'])->utc(),
        ));
    } finally {
        $connection->select('SELECT pg_advisory_unlock_shared(?)', [$lockKey]);
    }

    $finish(['result' => 'created', 'id' => $booking->getKey()]);
} catch (BookingConflictException) {
    $finish(['result' => 'conflict']);
} catch (Throwable $exception) {
    $finish(['result' => 'error', 'message' => "{$stage}: ".$exception->getMessage()]);
}
