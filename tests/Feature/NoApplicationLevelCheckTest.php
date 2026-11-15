<?php

namespace Tests\Feature;

use App\Actions\CreateBooking;
use App\Data\CreateBookingData;
use App\Exceptions\BookingConflictException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * A não sobreposição vem apenas da exclusion constraint: a Action não consulta bookings antes do insert.
 */
class NoApplicationLevelCheckTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    public function test_create_booking_issues_no_overlap_select_before_insert(): void
    {
        $data = $this->bookingData($this->bookableScenario());

        /** @var list<string> $queries */
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(CreateBooking::class)->handle($data);

        $insertIndex = null;
        foreach ($queries as $index => $sql) {
            if (str_starts_with($sql, 'insert into "bookings"')) {
                $insertIndex = $index;
                break;
            }
        }

        $this->assertNotNull($insertIndex, 'CreateBooking did not insert into "bookings".');

        $selectsOnBookingsBeforeInsert = array_filter(
            array_slice($queries, 0, $insertIndex),
            fn (string $sql): bool => str_starts_with(ltrim($sql), 'select') && str_contains($sql, '"bookings"'),
        );

        $this->assertSame([], array_values($selectsOnBookingsBeforeInsert));
    }

    public function test_action_throws_conflict_exception_on_second_overlapping_insert(): void
    {
        $data = $this->bookingData($this->bookableScenario());
        $action = app(CreateBooking::class);

        $action->handle($data);

        try {
            $action->handle($data);
            $this->fail('Expected BookingConflictException on the second overlapping insert.');
        } catch (BookingConflictException $exception) {
            $this->assertSame(BookingConflictException::MESSAGE, $exception->getMessage());
        }

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @param  array{company: Company, professional: Professional, customer: Customer, service: Service}  $scenario
     */
    private function bookingData(array $scenario): CreateBookingData
    {
        return new CreateBookingData(
            companyId: $scenario['company']->getKey(),
            professionalId: $scenario['professional']->getKey(),
            customerId: $scenario['customer']->getKey(),
            serviceId: $scenario['service']->getKey(),
            startsAt: CarbonImmutable::now()->utc()->addDay()->setTime(14, 0),
        );
    }
}
