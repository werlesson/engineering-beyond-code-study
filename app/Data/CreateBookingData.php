<?php

namespace App\Data;

use App\Http\Requests\StoreBookingRequest;
use Carbon\CarbonImmutable;

/**
 * Dados de criação de um booking, já validados e com a empresa vinda do usuário autenticado.
 */
final readonly class CreateBookingData
{
    public function __construct(
        public int $companyId,
        public int $professionalId,
        public int $customerId,
        public int $serviceId,
        public CarbonImmutable $startsAt,
    ) {}

    /**
     * Lê apenas os campos validados; company_id e ends_at do payload são ignorados.
     */
    public static function fromRequest(StoreBookingRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            companyId: $request->user()->company_id,
            professionalId: (int) $validated['professional_id'],
            customerId: (int) $validated['customer_id'],
            serviceId: (int) $validated['service_id'],
            startsAt: CarbonImmutable::parse($validated['starts_at'])->utc(),
        );
    }
}
