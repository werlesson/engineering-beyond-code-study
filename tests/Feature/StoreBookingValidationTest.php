<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * Validação do payload de POST /api/bookings: toda falha é 422 no formato padrão e nada é persistido.
 */
class StoreBookingValidationTest extends TestCase
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
        $this->scenario = $this->bookableScenario();
        Sanctum::actingAs($this->scenario['user']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'professional_id' => ['professional_id'],
            'customer_id' => ['customer_id'],
            'service_id' => ['service_id'],
            'starts_at' => ['starts_at'],
        ];
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonIntegerIds(): array
    {
        $cases = [];

        foreach (['professional_id', 'customer_id', 'service_id'] as $field) {
            $cases["{$field} as string"] = [$field, 'abc'];
            $cases["{$field} as float"] = [$field, 1.5];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidStartsAtFormats(): array
    {
        return [
            'natural language' => ['amanhã'],
            'invalid month' => ['2026-13-01T10:00:00Z'],
            'without offset' => ['2026-01-02T14:00:00'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_each_required_field_missing_returns_422(string $field): void
    {
        $payload = $this->bookingPayload($this->scenario);
        unset($payload[$field]);

        $this->assertRejected($this->postJson(route('bookings.store'), $payload), $field);
    }

    #[DataProvider('nonIntegerIds')]
    public function test_non_integer_ids_return_422(string $field, mixed $value): void
    {
        $response = $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [$field => $value]));

        $this->assertRejected($response, $field);
    }

    public function test_nonexistent_ids_return_422(): void
    {
        $response = $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'professional_id' => PHP_INT_MAX,
            'customer_id' => PHP_INT_MAX,
            'service_id' => PHP_INT_MAX,
        ]));

        $this->assertRejected($response, 'professional_id', 'customer_id', 'service_id');
    }

    #[DataProvider('invalidStartsAtFormats')]
    public function test_invalid_starts_at_format_returns_422(string $startsAt): void
    {
        $response = $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, ['starts_at' => $startsAt]));

        $this->assertRejected($response, 'starts_at');
    }

    public function test_starts_at_in_the_past_returns_422(): void
    {
        $response = $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'starts_at' => now()->subMinute()->toIso8601ZuluString(),
        ]));

        $this->assertRejected($response, 'starts_at');
    }

    public function test_starts_at_just_in_the_future_is_accepted(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'starts_at' => now()->addMinute()->toIso8601ZuluString(),
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_starts_at_accepts_offset_and_zulu(): void
    {
        $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'starts_at' => '2026-01-02T14:00:00-03:00',
        ]))->assertCreated();

        $this->postJson(route('bookings.store'), $this->bookingPayload($this->scenario, [
            'starts_at' => '2026-01-03T14:00:00Z',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * 422 no formato padrão do Laravel (message + errors) com erro nos campos esperados, sem persistir nada.
     */
    private function assertRejected(TestResponse $response, string ...$fields): void
    {
        $response->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors($fields);

        $this->assertDatabaseCount('bookings', 0);
    }
}
