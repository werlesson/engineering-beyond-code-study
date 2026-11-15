<?php

namespace Tests\Feature;

use App\Actions\CreateBooking;
use App\Data\CreateBookingData;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * Só a 23P01 é regra de negócio (409). Qualquer outra violação do banco é bug e vira 500.
 */
class UnexpectedDatabaseErrorTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    private const FOREIGN_KEY_VIOLATION = '23503';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    public function test_non_overlap_query_exception_returns_500(): void
    {
        $scenario = $this->bookableScenario();
        Sanctum::actingAs($scenario['user']);

        $this->app->instance(CreateBooking::class, new class extends CreateBooking
        {
            public function handle(CreateBookingData $data): Booking
            {
                $previous = new PDOException('SQLSTATE[23503]: Foreign key violation');
                $previous->errorInfo = ['23503', 7, 'insert or update on table "bookings" violates foreign key constraint'];

                throw new QueryException('pgsql', 'insert into "bookings" ...', [], $previous);
            }
        });

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertInternalServerError();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_action_rethrows_non_overlap_query_exception_untouched(): void
    {
        $scenario = $this->bookableScenario();

        try {
            app(CreateBooking::class)->handle(new CreateBookingData(
                companyId: $scenario['company']->getKey(),
                professionalId: $scenario['professional']->getKey(),
                customerId: $scenario['customer']->getKey() + 1000,
                serviceId: $scenario['service']->getKey(),
                startsAt: CarbonImmutable::now()->utc()->addDay()->setTime(14, 0),
            ));
            $this->fail('Expected a QueryException for the missing customer.');
        } catch (BookingConflictException) {
            $this->fail('A non-overlap violation must not be translated into BookingConflictException.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->errorInfo[0] ?? null);
        }

        $this->assertDatabaseCount('bookings', 0);
    }
}
