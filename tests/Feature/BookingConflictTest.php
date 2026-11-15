<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * Conflito sequencial via POST /api/bookings: a exclusion constraint vira 409.
 *
 * Cenário base: booking confirmado de 14:00 a 15:00 (serviço de 60 min) para o profissional P.
 */
class BookingConflictTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    /**
     * @var array{company: Company, user: User, professional: Professional, customer: Customer, service: Service}
     */
    private array $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();

        $this->scenario = $this->bookableScenario(durationMinutes: 60);
        Sanctum::actingAs($this->scenario['user']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function partialOverlapProvider(): array
    {
        return [
            'starts inside the existing booking (14:30)' => [14, 30],
            'ends inside the existing booking (13:30)' => [13, 30],
        ];
    }

    #[DataProvider('partialOverlapProvider')]
    public function test_partial_overlap_returns_409(int $hour, int $minute): void
    {
        $this->existingBooking();

        $this->assertConflict(fn (): TestResponse => $this->postBooking($this->scenario['service'], $hour, $minute));
    }

    public function test_identical_interval_returns_409(): void
    {
        $this->existingBooking();

        $this->assertConflict(fn (): TestResponse => $this->postBooking($this->scenario['service'], 14, 0));
    }

    public function test_contained_and_containing_intervals_return_409(): void
    {
        $this->existingBooking();

        $this->assertConflict(fn (): TestResponse => $this->postBooking($this->offeredService(30), 14, 15));
        $this->assertConflict(fn (): TestResponse => $this->postBooking($this->offeredService(180), 13, 0));
    }

    public function test_conflict_response_body_has_expected_message(): void
    {
        $this->existingBooking();

        $this->postBooking($this->scenario['service'], 14, 0)
            ->assertConflict()
            ->assertExactJson(['message' => 'O profissional já possui um agendamento nesse horário.']);
    }

    public function test_adjacent_intervals_return_201(): void
    {
        $this->existingBooking();

        $this->postBooking($this->scenario['service'], 15, 0)->assertCreated();
        $this->postBooking($this->scenario['service'], 13, 0)->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_overlap_with_cancelled_booking_returns_201(): void
    {
        $this->existingBooking(BookingStatus::Cancelled);

        $this->postBooking($this->scenario['service'], 14, 0)->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_overlap_with_other_professional_returns_201(): void
    {
        $this->existingBooking();
        $otherProfessional = Professional::factory()
            ->for($this->scenario['company'])
            ->offering($this->scenario['service'])
            ->create();

        $this->postBooking($this->scenario['service'], 14, 0, $otherProfessional)->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_request_after_conflict_succeeds_in_same_test(): void
    {
        $this->existingBooking();

        $this->assertConflict(fn (): TestResponse => $this->postBooking($this->scenario['service'], 14, 30));

        $this->postBooking($this->scenario['service'], 16, 0)->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertSame(2, Booking::confirmed()->where('professional_id', $this->scenario['professional']->getKey())->count());
    }

    /**
     * Booking de 14:00 a 15:00 de amanhã para o profissional P.
     */
    private function existingBooking(BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return Booking::factory()
            ->for($this->scenario['professional'], $this->scenario['service'], $this->scenario['customer'])
            ->startingAt($this->tomorrowAt(14, 0))
            ->create(['status_id' => $status]);
    }

    /**
     * Novo serviço da mesma empresa, oferecido pelo profissional P.
     */
    private function offeredService(int $durationMinutes): Service
    {
        $service = Service::factory()->for($this->scenario['company'])->duration($durationMinutes)->create();
        $this->scenario['professional']->offer($service);

        return $service;
    }

    private function postBooking(Service $service, int $hour, int $minute, ?Professional $professional = null): TestResponse
    {
        return $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'professional_id' => ($professional ?? $this->scenario['professional'])->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => $this->tomorrowAt($hour, $minute)->toIso8601ZuluString(),
        ]));
    }

    /**
     * 409 com a mensagem exata e sem alterar a contagem de bookings.
     *
     * @param  Closure(): TestResponse  $request
     */
    private function assertConflict(Closure $request): void
    {
        $countBefore = Booking::count();

        $request()->assertConflict()->assertExactJson(['message' => BookingConflictException::MESSAGE]);

        $this->assertSame($countBefore, Booking::count());
    }

    private function tomorrowAt(int $hour, int $minute): CarbonImmutable
    {
        return CarbonImmutable::now()->utc()->addDay()->setTime($hour, $minute);
    }
}
