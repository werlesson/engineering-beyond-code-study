<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Gera bookings válidos sob todas as constraints: profissional, cliente e serviço da mesma
 * company, serviço oferecido pelo profissional e ends_at derivado da duração do serviço.
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'professional_id' => fn (array $attributes): int => Professional::factory()
                ->create(['company_id' => $attributes['company_id']])
                ->getKey(),
            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['company_id' => $attributes['company_id']])
                ->getKey(),
            'service_id' => fn (array $attributes): int => $this->createOfferedService(
                $attributes['company_id'],
                $attributes['professional_id'],
            ),
            'status_id' => BookingStatus::Confirmed,
            'starts_at' => now()->utc()->addDay()->setTime(14, 0)->toImmutable(),
            'ends_at' => fn (array $attributes): CarbonImmutable => self::endsAt($attributes),
        ];
    }

    /**
     * Indicate that the booking is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_id' => BookingStatus::Cancelled,
        ]);
    }

    /**
     * Agenda o booking para o profissional, serviço e cliente informados.
     *
     * Chamado com (Professional, Service, Customer), usa a company do profissional e não cria
     * o vínculo na pivô: o banco rejeita um serviço não oferecido. Com outros argumentos,
     * mantém o comportamento padrão do Factory::for() (relacionamento pai).
     *
     * @param  Professional|Factory<Model>|Model  $factory
     * @param  Service|string|null  $relationship
     */
    public function for($factory, $relationship = null, ?Customer $customer = null): static
    {
        if (! $factory instanceof Professional || ! $relationship instanceof Service || $customer === null) {
            return parent::for($factory, $relationship);
        }

        return $this->state(fn (array $attributes) => [
            'company_id' => $factory->company_id,
            'professional_id' => $factory->getKey(),
            'service_id' => $relationship->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
    }

    /**
     * Define o início do booking (normalizado para UTC) e recalcula ends_at pela duração do serviço.
     */
    public function startingAt(CarbonImmutable $startsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt->utc(),
            'ends_at' => fn (array $attributes): CarbonImmutable => self::endsAt($attributes),
        ]);
    }

    /**
     * Cria um serviço na company e o vincula ao profissional na pivô.
     */
    private function createOfferedService(int $companyId, int $professionalId): int
    {
        $service = Service::factory()->create(['company_id' => $companyId]);

        Professional::findOrFail($professionalId)->offer($service);

        return $service->getKey();
    }

    /**
     * ends_at = starts_at + duração do serviço.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function endsAt(array $attributes): CarbonImmutable
    {
        $startsAt = $attributes['starts_at'] instanceof DateTimeInterface
            ? CarbonImmutable::instance($attributes['starts_at'])
            : CarbonImmutable::parse($attributes['starts_at']);

        return $startsAt->utc()->addMinutes(Service::findOrFail($attributes['service_id'])->duration_minutes);
    }
}
