<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação pública de um booking: datas em UTC (Zulu) e status como slug,
 * sem expor company_id nem status_id.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * Formato de todas as datas da API.
     */
    private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, professional_id: int, customer_id: int, service_id: int, starts_at: string, ends_at: string, status: string, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'customer_id' => $this->customer_id,
            'service_id' => $this->service_id,
            'starts_at' => $this->starts_at->utc()->format(self::DATE_FORMAT),
            'ends_at' => $this->ends_at->utc()->format(self::DATE_FORMAT),
            'status' => $this->status_id->slug(),
            'created_at' => $this->created_at->utc()->format(self::DATE_FORMAT),
        ];
    }
}
