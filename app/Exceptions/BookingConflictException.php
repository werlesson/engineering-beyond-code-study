<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * O profissional já possui um booking confirmado que se sobrepõe ao intervalo pedido.
 *
 * Lançada pela CreateBooking quando o PostgreSQL rejeita o insert pela exclusion constraint
 * bookings_no_overlap (SQLSTATE 23P01).
 */
class BookingConflictException extends Exception
{
    public const MESSAGE = 'O profissional já possui um agendamento nesse horário.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    /**
     * Traduz o conflito para 409 Conflict.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE], 409);
    }
}
