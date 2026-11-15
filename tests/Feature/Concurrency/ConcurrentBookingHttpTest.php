<?php

namespace Tests\Feature\Concurrency;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ConcurrencyScenario;
use Tests\Support\TestHttpServer;
use Tests\TestCase;
use Throwable;

/**
 * Concorrência HTTP ponta a ponta: 5 POST /api/bookings idênticos e simultâneos contra um
 * servidor real multi-worker (Sanctum, Form Request, Action, tradução para 409 e rota).
 *
 * O resultado é determinístico mesmo que o servidor serialize parte das requisições: as
 * processadas em sequência também recebem 409. A prova da corrida no banco é o ConcurrentBookingTest.
 */
#[Group('concurrency')]
class ConcurrentBookingHttpTest extends TestCase
{
    use ConcurrencyScenario;

    private const REQUESTS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    private ?TestHttpServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyScenario();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->stop();
            $this->server = null;
        } finally {
            $this->tearDownConcurrencyScenario();

            parent::tearDown();
        }
    }

    public function test_five_simultaneous_http_requests_yield_one_201_and_four_409(): void
    {
        $token = $this->user->createToken('concurrency')->plainTextToken;
        $startsAt = CarbonImmutable::now()->utc()->addDays(30)->setTime(14, 0);
        $payload = [
            'professional_id' => $this->professional->getKey(),
            'customer_id' => $this->customer->getKey(),
            'service_id' => $this->service->getKey(),
            'starts_at' => $startsAt->toIso8601String(),
        ];

        $this->server = new TestHttpServer($this->childProcessEnvironment());
        $this->server->start();
        $url = $this->server->url('/api/bookings');

        $responses = Http::pool(function (Pool $pool) use ($url, $token, $payload): array {
            $requests = [];

            for ($request = 1; $request <= self::REQUESTS; $request++) {
                $requests[] = $pool->as("request-{$request}")
                    ->withToken($token)
                    ->acceptJson()
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->post($url, $payload);
            }

            return $requests;
        });

        $statuses = [];

        foreach ($responses as $key => $response) {
            if ($response instanceof Throwable) {
                $this->fail("request: [{$key}] failed or timed out: {$response->getMessage()}");
            }

            /** @var Response $response */
            $this->assertLessThan(500, $response->status(), "request: [{$key}] returned {$response->status()}: {$response->body()}");

            $statuses[] = $response->status();

            if ($response->status() === 201) {
                $this->assertIsArray($response->json('data'));
                $this->assertSame($this->professional->getKey(), $response->json('data.professional_id'));
                $this->assertSame($startsAt->format('Y-m-d\TH:i:s\Z'), $response->json('data.starts_at'));
                $this->assertSame('confirmed', $response->json('data.status'));
            } else {
                $this->assertSame(409, $response->status(), "request: [{$key}] returned {$response->status()}: {$response->body()}");
                $this->assertSame(['message' => BookingConflictException::MESSAGE], $response->json());
            }
        }

        sort($statuses);
        $this->assertSame([201, 409, 409, 409, 409], $statuses);

        $this->assertSame(1, Booking::confirmed()
            ->where('professional_id', $this->professional->getKey())
            ->where('starts_at', $startsAt->toIso8601String())
            ->where('ends_at', $startsAt->addMinutes(60)->toIso8601String())
            ->count());
    }
}
