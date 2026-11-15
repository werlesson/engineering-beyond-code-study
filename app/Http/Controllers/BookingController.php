<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Data\CreateBookingData;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    /**
     * Cria um booking para a empresa do usuário autenticado.
     */
    public function store(StoreBookingRequest $request, CreateBooking $createBooking): JsonResponse
    {
        $booking = $createBooking->handle(CreateBookingData::fromRequest($request));

        return BookingResource::make($booking)->response()->setStatusCode(201);
    }
}
