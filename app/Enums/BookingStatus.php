<?php

namespace App\Enums;

/**
 * Espelho na aplicação da lookup booking_statuses (IDs fixos). Não existe coluna enum no banco.
 */
enum BookingStatus: int
{
    case Confirmed = 1;
    case Cancelled = 2;

    /**
     * Slug correspondente à coluna booking_statuses.slug.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Confirmed => 'confirmed',
            self::Cancelled => 'cancelled',
        };
    }
}
