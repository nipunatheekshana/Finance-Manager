<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CardPaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultipleCreditCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-30');
    }

    #[Test]
    public function each_credit_card_gets_its_own_payment_method(): void
    {
        $user = $this->makeUser();

        $visa = $this->addCard($user, 'HSBC Visa', '200000.00');
        $amex = $this->addCard($user, 'Amex Gold', '80000.00');
        $store = $this->addCard($user, 'Store Card', '30000.00');

        $links = PaymentMethod::query()
            ->where('user_id', $user->id)
            ->whereNotNull('debt_id')
            ->pluck('debt_id', 'name')
            ->all();

        $this->assertSame(
            ['HSBC Visa' => $visa->id, 'Amex Gold' => $amex->id, 'Store Card' => $store->id],
            $links,
        );

        // Three cards, three debts, three distinct links.
        $this->assertSame(3, $user->debts()->where('type', 'credit_card')->count());
        $this->assertCount(3, array_unique(array_values($links)));
    }

    #[Test]
    public function spending_is_charged_to_the_card_it_was_made_on(): void
    {
        $user = $this->makeUser();

        $visa = $this->addCard($user, 'HSBC Visa', '200000.00');
        $amex = $this->addCard($user, 'Amex Gold', '80000.00');

        $this->spendOn($user, 'Amex Gold', '5000.00');

        // Only the card that was used moves.
        $this->assertSame('85000.00', $amex->fresh()->current_balance);
        $this->assertSame('200000.00', $visa->fresh()->current_balance);

        $this->spendOn($user, 'HSBC Visa', '12000.00');

        $this->assertSame('212000.00', $visa->fresh()->current_balance);
        $this->assertSame('85000.00', $amex->fresh()->current_balance);
    }

    #[Test]
    public function paying_one_card_leaves_the_others_untouched(): void
    {
        $user = $this->makeUser();

        $visa = $this->addCard($user, 'HSBC Visa', '200000.00');
        $amex = $this->addCard($user, 'Amex Gold', '80000.00');

        $this->actingAs($user)
            ->postJson("/api/debts/{$visa->id}/payments", ['amount' => '50000.00'])
            ->assertCreated();

        $this->assertSame('150000.00', $visa->fresh()->current_balance);
        $this->assertSame('80000.00', $amex->fresh()->current_balance);
    }

    #[Test]
    public function each_card_gets_its_own_payoff_estimate(): void
    {
        $user = $this->makeUser();

        $visa = $this->addCard($user, 'HSBC Visa', '300000.00', planned: '100000.00');
        $store = $this->addCard($user, 'Store Card', '30000.00', planned: '15000.00');

        $visaMonths = $this->actingAs($user)
            ->getJson("/api/debts/{$visa->id}/payoff")->json('data.estimated_months');
        $storeMonths = $this->actingAs($user)
            ->getJson("/api/debts/{$store->id}/payoff")->json('data.estimated_months');

        $this->assertSame(3, $visaMonths);
        $this->assertSame(2, $storeMonths);
    }

    #[Test]
    public function the_debt_total_adds_every_card_together(): void
    {
        $user = $this->makeUser();

        $this->addCard($user, 'HSBC Visa', '200000.00');
        $this->addCard($user, 'Amex Gold', '80000.00');

        $this->actingAs($user)
            ->getJson('/api/debts')
            ->assertOk()
            ->assertJsonPath('meta.total_balance', '280000.00');
    }

    #[Test]
    public function onboarding_with_several_cards_links_each_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/onboarding', [
            'income_mode' => 'salaried',
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
            'debts' => [
                ['name' => 'HSBC Visa', 'type' => 'credit_card', 'current_balance' => '200000.00', 'planned_payment' => '50000.00'],
                ['name' => 'Amex Gold', 'type' => 'credit_card', 'current_balance' => '80000.00', 'planned_payment' => '20000.00'],
                ['name' => 'Lees', 'type' => 'installment', 'current_balance' => '462000.00', 'planned_payment' => '42000.00', 'remaining_installments' => 11],
            ],
        ])->assertCreated();

        $user->refresh();

        $this->assertSame(3, $user->debts()->count());

        $cardIds = $user->debts()->where('type', 'credit_card')->pluck('id')->sort()->values()->all();
        $linked = $user->paymentMethods()->whereNotNull('debt_id')->pluck('debt_id')->sort()->values()->all();

        // Both cards linked, and the installment debt is not spendable.
        $this->assertSame($cardIds, $linked);
        $this->assertCount(2, $linked);
    }

    #[Test]
    public function spending_on_separate_cards_after_onboarding_stays_separate(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/onboarding', [
            'income_mode' => 'salaried',
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
            'debts' => [
                ['name' => 'HSBC Visa', 'type' => 'credit_card', 'current_balance' => '100000.00'],
                ['name' => 'Amex Gold', 'type' => 'credit_card', 'current_balance' => '50000.00'],
            ],
        ])->assertCreated();

        $user->refresh();

        $this->spendOn($user, 'HSBC Visa', '3000.00');
        $this->spendOn($user, 'Amex Gold', '7000.00');

        // Each card carries only its own spending.
        $this->assertSame('103000.00', $user->debts()->where('name', 'HSBC Visa')->value('current_balance'));
        $this->assertSame('57000.00', $user->debts()->where('name', 'Amex Gold')->value('current_balance'));
    }

    #[Test]
    public function the_first_card_adopts_the_generic_method_rather_than_duplicating_it(): void
    {
        $user = $this->makeUser();

        $this->addCard($user, 'HSBC Visa', '100000.00');

        // No leftover unlinked "Credit Card" entry alongside the real card.
        $this->assertSame(
            0,
            $user->paymentMethods()->where('name', 'Credit Card')->count(),
        );
        $this->assertSame(1, $user->paymentMethods()->where('type', 'credit_card')->count());
        $this->assertSame('HSBC Visa', $user->paymentMethods()->where('type', 'credit_card')->value('name'));
    }

    #[Test]
    public function renaming_a_card_renames_its_payment_method(): void
    {
        $user = $this->makeUser();
        $card = $this->addCard($user, 'HSBC Visa', '100000.00');

        $this->actingAs($user)
            ->putJson("/api/debts/{$card->id}", ['name' => 'HSBC Platinum'])
            ->assertOk();

        $this->assertSame(
            'HSBC Platinum',
            PaymentMethod::where('debt_id', $card->id)->value('name'),
        );
    }

    #[Test]
    public function a_payment_method_the_user_renamed_themselves_is_left_alone(): void
    {
        $user = $this->makeUser();
        $card = $this->addCard($user, 'HSBC Visa', '100000.00');

        PaymentMethod::where('debt_id', $card->id)->update(['name' => 'My everyday card']);

        $this->actingAs($user)
            ->putJson("/api/debts/{$card->id}", ['name' => 'HSBC Platinum'])
            ->assertOk();

        $this->assertSame(
            'My everyday card',
            PaymentMethod::where('debt_id', $card->id)->value('name'),
        );
    }

    #[Test]
    public function a_card_name_that_clashes_with_an_existing_method_is_suffixed(): void
    {
        $user = $this->makeUser();

        // "Cash" is a seeded method, so the card cannot take that exact name.
        $card = $this->addCard($user, 'Cash', '10000.00');

        $this->assertSame('Cash 2', PaymentMethod::where('debt_id', $card->id)->value('name'));
        $this->assertSame(2, $user->paymentMethods()->whereIn('name', ['Cash', 'Cash 2'])->count());
    }

    #[Test]
    public function deleting_an_unused_card_hides_its_payment_method(): void
    {
        $user = $this->makeUser();
        $card = $this->addCard($user, 'Store Card', '10000.00');
        $methodId = PaymentMethod::where('debt_id', $card->id)->value('id');

        $this->actingAs($user)->deleteJson("/api/debts/{$card->id}")->assertOk();

        $method = PaymentMethod::find($methodId);
        $this->assertNotNull($method);
        $this->assertFalse($method->active);
        $this->assertNull($method->debt_id);
    }

    #[Test]
    public function deleting_a_used_card_keeps_its_payment_method_for_history(): void
    {
        $user = $this->makeUser();
        $card = $this->addCard($user, 'Store Card', '10000.00');
        $this->spendOn($user, 'Store Card', '2500.00');

        $methodId = PaymentMethod::where('debt_id', $card->id)->value('id');

        $this->actingAs($user)->deleteJson("/api/debts/{$card->id}")->assertOk();

        $method = PaymentMethod::find($methodId);
        $this->assertTrue($method->active);
    }

    #[Test]
    public function a_non_card_debt_gets_no_payment_method(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/debts', [
            'name' => 'Lees',
            'type' => 'installment',
            'current_balance' => '462000.00',
            'planned_payment' => '42000.00',
        ])->assertCreated()->assertJsonPath('payment_method', null);

        $this->assertSame(0, $user->paymentMethods()->whereNotNull('debt_id')->count());
    }

    #[Test]
    public function existing_cards_without_a_link_can_be_backfilled(): void
    {
        $user = $this->makeUser();

        // Two cards created directly, bypassing the service.
        Debt::create([
            'user_id' => $user->id, 'name' => 'Old Visa', 'type' => 'credit_card',
            'original_amount' => '50000.00', 'current_balance' => '50000.00', 'status' => 'active',
        ]);
        Debt::create([
            'user_id' => $user->id, 'name' => 'Old Amex', 'type' => 'credit_card',
            'original_amount' => '20000.00', 'current_balance' => '20000.00', 'status' => 'active',
        ]);

        $this->assertSame(0, $user->paymentMethods()->whereNotNull('debt_id')->count());

        $linked = app(CardPaymentMethodService::class)->backfillFor($user->fresh());

        $this->assertSame(2, $linked);
        $this->assertSame(2, $user->paymentMethods()->whereNotNull('debt_id')->count());
    }

    private function addCard(User $user, string $name, string $balance, string $planned = '10000.00'): Debt
    {
        $id = $this->actingAs($user)->postJson('/api/debts', [
            'name' => $name,
            'type' => 'credit_card',
            'current_balance' => $balance,
            'credit_limit' => '500000.00',
            'planned_payment' => $planned,
            'minimum_payment' => '1000.00',
        ])->assertCreated()->json('data.id');

        return Debt::findOrFail($id);
    }

    private function spendOn(User $user, string $methodName, string $amount): void
    {
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => $amount,
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, $methodName),
            'expense_date' => '2026-09-20',
        ])->assertCreated();
    }
}
