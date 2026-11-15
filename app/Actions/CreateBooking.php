<?php

namespace App\Actions;

use App\Data\CreateBookingData;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;

/**
 * Única via de criação de bookings, reutilizada pelo controller e pelo worker de concorrência.
 *
 * Não verifica sobreposição antes de inserir: a não sobreposição é garantida pela exclusion
 * constraint do banco.
 */
class CreateBooking
{
    /**
     * Cria um booking confirmado com ends_at = starts_at + duração atual do serviço.
     */
    public function handle(CreateBookingData $data): Booking
    {
        $service = Service::where('company_id', $data->companyId)->findOrFail($data->serviceId);

        return Booking::create([
            'company_id' => $data->companyId,
            'professional_id' => $data->professionalId,
            'customer_id' => $data->customerId,
            'service_id' => $service->getKey(),
            'status_id' => BookingStatus::Confirmed,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->startsAt->addMinutes($service->duration_minutes),
        ]);
    }
}
