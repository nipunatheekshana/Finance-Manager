<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProtectedRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every financial endpoint must reject an anonymous request.
     */
    #[DataProvider('protectedEndpoints')]
    #[Test]
    public function it_rejects_anonymous_requests(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public static function protectedEndpoints(): array
    {
        return [
            ['GET', '/api/dashboard'],
            ['GET', '/api/expenses'],
            ['POST', '/api/expenses'],
            ['GET', '/api/categories'],
            ['GET', '/api/payment-methods'],
            ['GET', '/api/recurring-transactions'],
            ['GET', '/api/monthly-plans'],
            ['GET', '/api/monthly-plans/current'],
            ['GET', '/api/debts'],
            ['GET', '/api/savings-goals'],
            ['GET', '/api/cash-flow'],
            ['GET', '/api/calendar'],
            ['GET', '/api/financial-health'],
            ['POST', '/api/affordability-check'],
            ['GET', '/api/reports/spending'],
            ['GET', '/api/reports/monthly'],
            ['GET', '/api/alerts'],
            ['GET', '/api/profile'],
            ['GET', '/api/income'],
            ['GET', '/api/onboarding'],
        ];
    }
}
