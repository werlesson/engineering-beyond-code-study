<?php

namespace App\Actions;

use App\Data\CreateBookingData;
use App\Enums\BookingStatus;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Única via de criação de bookings, reutilizada pelo controller e pelo worker de concorrência.
 *
 * Não verifica sobreposição antes de inserir: a não sobreposição é garantida pela exclusion
 * constraint do banco.
 */
class CreateBooking
{
    /**
     * SQLSTATE de exclusion_violation no PostgreSQL.
     */
    private const EXCLUSION_VIOLATION = '23P01';

    /**
     * Nome da exclusion constraint que impede sobreposição de bookings confirmados.
     */
    private const NO_OVERLAP_CONSTRAINT = 'bookings_no_overlap';

    /**
     * Cria um booking confirmado com ends_at = starts_at + duração atual do serviço.
     *
     * O insert usa `ON CONFLICT DO NOTHING RETURNING id`, que tem a exclusion constraint
     * bookings_no_overlap como árbitro. Com um INSERT simples, inserts concorrentes do mesmo
     * intervalo enxergam as tuplas ainda não confirmadas uns dos outros, esperam entre si e
     * entram em deadlock (40P01), quebrado só após o deadlock_timeout, uma rodada por par. A
     * inserção especulativa do ON CONFLICT evita esse ciclo: o perdedor apenas não insere.
     * Nenhuma linha devolvida significa conflito.
     *
     * A tradução da violação 23P01 é mantida como defesa: com o ON CONFLICT o insert atual
     * não a lança, mas qualquer insert sem árbitro nessa constraint a lançaria. O insert roda
     * numa transação curta (um savepoint quando já existe transação aberta), desfeita antes
     * de a exceção sair do closure; por isso a captura fica fora dele. Qualquer outra
     * QueryException é relançada sem tratamento.
     *
     * @throws BookingConflictException
     */
    public function handle(CreateBookingData $data): Booking
    {
        $service = Service::where('company_id', $data->companyId)->findOrFail($data->serviceId);

        $booking = new Booking([
            'company_id' => $data->companyId,
            'professional_id' => $data->professionalId,
            'customer_id' => $data->customerId,
            'service_id' => $service->getKey(),
            'status_id' => BookingStatus::Confirmed,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->startsAt->addMinutes($service->duration_minutes),
        ]);
        $booking->updateTimestamps();

        try {
            $inserted = DB::transaction(fn (): Collection => $booking->newQuery()->toBase()->insertOrIgnoreReturning(
                $booking->getAttributes(),
                [$booking->getKeyName()],
            ));
        } catch (QueryException $exception) {
            if ($this->isOverlapViolation($exception)) {
                throw new BookingConflictException($exception);
            }

            throw $exception;
        }

        if ($inserted->isEmpty()) {
            throw new BookingConflictException;
        }

        return $this->markAsPersisted($booking, $inserted->sole()->{$booking->getKeyName()});
    }

    private function isOverlapViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION
            && str_contains($exception->getMessage(), self::NO_OVERLAP_CONSTRAINT);
    }

    /**
     * Deixa o modelo no mesmo estado de um Booking::create() bem-sucedido.
     */
    private function markAsPersisted(Booking $booking, int $id): Booking
    {
        $booking->setAttribute($booking->getKeyName(), $id);
        $booking->exists = true;
        $booking->wasRecentlyCreated = true;
        $booking->syncOriginal();

        return $booking;
    }
}
