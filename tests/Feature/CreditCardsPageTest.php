<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\User;
use App\Services\CardPaymentMethodService;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The card as a spending instrument: its limit, what is on it, what is left,
 * and where it is heading. Paying it is the Debts screen's job.
 */
class CreditCardsPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_describes_each_card_against_its_limit(): void
    {
        [$user, $card, $method] = $this->cardWithPurchases();

        $cards = $this->actingAs($user)->getJson('/api/credit-cards')->assertOk()->json('data.cards');

        $this->assertCount(1, $cards);
        $this->assertSame('Visa', $cards[0]['name']);
        $this->assertSame('300000.00', $cards[0]['credit_limit']);
        $this->assertSame('124000.00', $cards[0]['balance']);
        $this->assertSame('176000.00', $cards[0]['available']);
        $this->assertSame('24000.00', $cards[0]['charged_this_cycle']);
        $this->assertSame('15000.00', $cards[0]['planned_payment']);
        $this->assertTrue($cards[0]['is_growing']);
        $this->assertTrue($cards[0]['payoff']['is_estimate']);
    }

    #[Test]
    public function it_lists_recent_purchases_and_charges_by_cycle_for_the_chart(): void
    {
        [$user] = $this->cardWithPurchases();

        $card = $this->actingAs($user)->getJson('/api/credit-cards')->json('data.cards.0');

        $this->assertCount(2, $card['recent_purchases']);
        $this->assertSame('15000.00', $card['recent_purchases'][0]['amount']);

        $this->assertNotEmpty($card['charges_by_cycle']);
        $this->assertSame('24000.00', end($card['charges_by_cycle'])['charged']);
    }

    #[Test]
    public function a_card_with_no_limit_has_no_available_figure_rather_than_a_wrong_one(): void
    {
        $user = $this->makeUser();
        Debt::create([
            'user_id' => $user->id, 'name' => 'Store card', 'type' => 'credit_card',
            'original_amount' => '5000.00', 'current_balance' => '5000.00',
            'minimum_payment' => '500.00', 'planned_payment' => '1000.00', 'interest_rate' => '30.00', 'due_day' => 5,
        ]);

        $card = $this->actingAs($user)->getJson('/api/credit-cards')->json('data.cards.0');

        $this->assertNull($card['credit_limit']);
        $this->assertNull($card['available']);
        $this->assertNull($card['utilisation_percentage']);
    }

    #[Test]
    public function another_accounts_cards_are_never_listed(): void
    {
        $this->cardWithPurchases();

        $cards = $this->actingAs($this->makeUser())->getJson('/api/credit-cards')->json('data.cards');

        $this->assertSame([], $cards);
    }

    /** @return array{0: User, 1: Debt, 2: \App\Models\PaymentMethod} */
    private function cardWithPurchases(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $card = Debt::create([
            'user_id' => $user->id, 'name' => 'Visa', 'type' => 'credit_card',
            'original_amount' => '100000.00', 'current_balance' => '100000.00', 'credit_limit' => '300000.00',
            'minimum_payment' => '5000.00', 'planned_payment' => '15000.00', 'interest_rate' => '24.00', 'due_day' => 15,
        ]);
        $method = app(CardPaymentMethodService::class)->ensureFor($card);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        foreach (['9000.00', '15000.00'] as $amount) {
            $this->actingAs($user)->postJson('/api/expenses', [
                'amount' => $amount,
                'expense_date' => '2026-08-28',
                'category_id' => $this->categoryId($user, 'Shopping'),
                'payment_method_id' => $method->id,
            ])->assertCreated();
        }

        return [$user->fresh(), $card->fresh(), $method];
    }
}
