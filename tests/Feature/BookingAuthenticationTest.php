<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BookingScenario;
use Tests\TestCase;

/**
 * POST /api/bookings exige um token Sanctum válido; os casos 401 usam headers reais.
 */
class BookingAuthenticationTest extends TestCase
{
    use BookingScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_request_without_authorization_header_returns_401(): void
    {
        $scenario = $this->bookableScenario();

        $this->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_request_with_invalid_token_returns_401(): void
    {
        $scenario = $this->bookableScenario();

        $this->withToken(Str::random(40))
            ->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_request_with_revoked_token_returns_401(): void
    {
        $scenario = $this->bookableScenario();
        $token = $scenario['user']->createToken('api');
        $token->accessToken->delete();

        $this->withToken($token->plainTextToken)
            ->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_request_with_expired_token_returns_401(): void
    {
        $scenario = $this->bookableScenario();
        $token = $scenario['user']->createToken('api', ['*'], now()->subMinute());

        $this->withToken($token->plainTextToken)
            ->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_valid_token_is_accepted(): void
    {
        $scenario = $this->bookableScenario();
        $token = $scenario['user']->createToken('api');

        $this->withToken($token->plainTextToken)
            ->postJson(route('bookings.store'), $this->bookingPayload($scenario))
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }
}
