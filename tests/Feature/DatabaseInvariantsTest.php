<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RawFixtures;
use Tests\TestCase;

/**
 * Invariantes CHECK, NOT NULL e de status impostas pelo banco, provadas com inserts diretos.
 */
class DatabaseInvariantsTest extends TestCase
{
    use RawFixtures;
    use RefreshDatabase;

    private const CHECK_VIOLATION = '23514';

    private const FOREIGN_KEY_VIOLATION = '23503';

    private const NOT_NULL_VIOLATION = '23502';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_booking_with_ends_at_equal_to_starts_at_fails(): void
    {
        $offer = $this->bookableOffer();

        $this->assertSqlState(self::CHECK_VIOLATION, fn () => $this->booking($offer + [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]));

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_with_ends_at_before_starts_at_fails(): void
    {
        $offer = $this->bookableOffer();

        $this->assertSqlState(self::CHECK_VIOLATION, fn () => $this->booking($offer + [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->subMinutes(30),
        ]));

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_with_unknown_status_id_fails(): void
    {
        $offer = $this->bookableOffer();

        $this->assertSqlState(self::FOREIGN_KEY_VIOLATION, fn () => $this->booking($offer + [
            'status_id' => 99,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]));

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_service_with_zero_or_negative_duration_fails(): void
    {
        $companyId = $this->company();

        $this->assertSqlState(self::CHECK_VIOLATION, fn () => $this->service($companyId, 0));
        $this->assertSqlState(self::CHECK_VIOLATION, fn () => $this->service($companyId, -1));

        $this->assertDatabaseCount('services', 0);
    }

    /**
     * @param  Closure(self): Closure  $operationFactory  recebe o teste e devolve a operação que insere o null
     */
    #[DataProvider('nullBusinessColumns')]
    public function test_null_business_columns_fail(string $table, Closure $operationFactory): void
    {
        $rowsBefore = DB::table($table)->count();

        $this->assertSqlState(self::NOT_NULL_VIOLATION, $operationFactory($this));

        $this->assertSame($rowsBefore, DB::table($table)->count());
    }

    /**
     * @return array<string, array{0: string, 1: Closure(self): Closure}>
     */
    public static function nullBusinessColumns(): array
    {
        $bookingWithNull = fn (string $column): Closure => function (self $test) use ($column): Closure {
            $attributes = $test->bookableOffer() + [
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ];

            return fn () => $test->booking([$column => null] + $attributes);
        };

        $cases = [];

        foreach (['company_id', 'professional_id', 'customer_id', 'service_id', 'starts_at', 'ends_at'] as $column) {
            $cases["bookings.{$column}"] = ['bookings', $bookingWithNull($column)];
        }

        $cases['services.name'] = ['services', function (self $test): Closure {
            $companyId = $test->company();

            return fn () => $test->service($companyId, 60, null);
        }];

        $cases['services.duration_minutes'] = ['services', function (self $test): Closure {
            $companyId = $test->company();

            return fn () => $test->service($companyId, null);
        }];

        $cases['professionals.name'] = ['professionals', function (self $test): Closure {
            $companyId = $test->company();

            return fn () => DB::table('professionals')->insert(['company_id' => $companyId, 'name' => null]);
        }];

        $cases['customers.name'] = ['customers', function (self $test): Closure {
            $companyId = $test->company();

            return fn () => DB::table('customers')->insert(['company_id' => $companyId, 'name' => null]);
        }];

        return $cases;
    }
}
