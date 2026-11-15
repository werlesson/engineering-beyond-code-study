<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'professional_id', 'customer_id', 'service_id', 'status_id', 'starts_at', 'ends_at'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status_id' => BookingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Apenas bookings confirmados (os únicos que ocupam a agenda).
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status_id', BookingStatus::Confirmed->value);
    }

    /**
     * Normaliza toda data para UTC, tanto na leitura (timestamptz volta com offset)
     * quanto na escrita (um Carbon em outro fuso é convertido antes de ser formatado).
     *
     * @param  mixed  $value
     */
    protected function asDateTime($value): CarbonInterface
    {
        return parent::asDateTime($value)->utc();
    }
}
