<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_can_record_an_expense(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '850.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-08-19',
            'description' => 'Rice & curry',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '850.00')
            ->assertJsonPath('data.description', 'Rice & curry');

        $this->assertDatabaseHas('expenses', [
            'user_id' => $user->id,
            'amount' => '850.00',
            'description' => 'Rice & curry',
        ]);
    }

    #[Test]
    public function an_expense_needs_an_amount_greater_than_zero(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '0',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function an_expense_rejects_more_than_two_decimal_places(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '10.999',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function an_expense_cannot_use_another_users_category(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '500.00',
            'category_id' => $this->categoryId($other, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function an_expense_cannot_use_another_users_payment_method(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '500.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($other, 'Cash'),
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
    }

    #[Test]
    public function a_user_can_update_their_own_expense(): void
    {
        $user = $this->makeUser();
        $expense = $this->createExpense($user, '850.00');

        $this->actingAs($user)
            ->putJson("/api/expenses/{$expense->id}", ['amount' => '1200.00', 'description' => 'Kottu'])
            ->assertOk()
            ->assertJsonPath('data.amount', '1200.00');

        $this->assertSame('1200.00', $expense->fresh()->amount);
    }

    #[Test]
    public function a_user_can_delete_their_own_expense(): void
    {
        $user = $this->makeUser();
        $expense = $this->createExpense($user, '850.00');

        $this->actingAs($user)->deleteJson("/api/expenses/{$expense->id}")->assertOk();

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    #[Test]
    public function a_user_cannot_see_another_users_expense(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $expense = $this->createExpense($other, '850.00');

        $this->actingAs($user)->getJson("/api/expenses/{$expense->id}")->assertForbidden();
    }

    #[Test]
    public function a_user_cannot_update_or_delete_another_users_expense(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $expense = $this->createExpense($other, '850.00');

        $this->actingAs($user)->putJson("/api/expenses/{$expense->id}", ['amount' => '1.00'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();

        $this->assertSame('850.00', $expense->fresh()->amount);
    }

    #[Test]
    public function the_expense_list_only_contains_the_signed_in_users_records(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->createExpense($user, '100.00');
        $this->createExpense($other, '999.00');

        $response = $this->actingAs($user)->getJson('/api/expenses')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('100.00', $response->json('data.0.amount'));
    }

    #[Test]
    public function the_expense_list_can_be_filtered_and_searched(): void
    {
        $user = $this->makeUser();

        $this->createExpense($user, '850.00', 'Food', 'Rice & curry', '2026-08-10');
        $this->createExpense($user, '1200.00', 'Transport', 'Uber', '2026-08-15');

        $byCategory = $this->actingAs($user)
            ->getJson('/api/expenses?category_id='.$this->categoryId($user, 'Transport'))
            ->assertOk();
        $this->assertCount(1, $byCategory->json('data'));
        $this->assertSame('1200.00', $byCategory->json('data.0.amount'));

        $bySearch = $this->actingAs($user)->getJson('/api/expenses?search=Uber')->assertOk();
        $this->assertCount(1, $bySearch->json('data'));

        $byDate = $this->actingAs($user)->getJson('/api/expenses?from=2026-08-12&to=2026-08-20')->assertOk();
        $this->assertCount(1, $byDate->json('data'));
    }

    #[Test]
    public function the_list_reports_the_total_across_the_whole_filtered_set(): void
    {
        $user = $this->makeUser();

        $this->createExpense($user, '850.00');
        $this->createExpense($user, '1150.00');

        $this->actingAs($user)
            ->getJson('/api/expenses')
            ->assertOk()
            ->assertJsonPath('meta.filtered_total', '2000.00');
    }

    #[Test]
    public function offline_expenses_sync_without_creating_duplicates(): void
    {
        $user = $this->makeUser();
        $uuid = '9f8a1b2c-3d4e-4f5a-8b9c-0d1e2f3a4b5c';

        $payload = [
            'expenses' => [
                [
                    'amount' => '850.00',
                    'category_id' => $this->categoryId($user, 'Food'),
                    'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
                    'expense_date' => '2026-08-19',
                    'client_uuid' => $uuid,
                ],
            ],
        ];

        $this->actingAs($user)->postJson('/api/expenses/sync', $payload)->assertOk();

        // Replaying the same queue must not create a second expense.
        $this->actingAs($user)->postJson('/api/expenses/sync', $payload)->assertOk();

        $this->assertSame(1, Expense::where('user_id', $user->id)->count());
    }

    #[Test]
    public function two_users_can_use_the_same_client_uuid_independently(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $uuid = '9f8a1b2c-3d4e-4f5a-8b9c-0d1e2f3a4b5c';

        foreach ([$user, $other] as $account) {
            $this->actingAs($account)->postJson('/api/expenses', [
                'amount' => '500.00',
                'category_id' => $this->categoryId($account, 'Food'),
                'payment_method_id' => $this->paymentMethodId($account, 'Cash'),
                'client_uuid' => $uuid,
            ])->assertCreated();
        }

        $this->assertSame(2, Expense::where('client_uuid', $uuid)->count());
    }

    private function createExpense(
        \App\Models\User $user,
        string $amount,
        string $category = 'Food',
        ?string $description = null,
        string $date = '2026-08-19',
    ): Expense {
        return Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, $category),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => $date,
            'description' => $description,
        ]);
    }
}
