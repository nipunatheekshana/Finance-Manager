<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Keeps every card-backed debt paired with its own payment method.
 *
 * A user can hold any number of credit cards, and each one is a separate debt
 * with a separate balance. Spending has to be attributable to the specific card
 * it was charged to, which means one payment method per card rather than a
 * single shared "Credit Card" entry that silently charges whichever card
 * happened to be created first.
 */
class CardPaymentMethodService
{
    /** Debt types that money can be spent against directly. */
    private const SPENDABLE_TYPES = ['credit_card'];

    /**
     * Make sure this debt has a payment method pointing at it, creating one if
     * needed. Safe to call repeatedly.
     */
    public function ensureFor(Debt $debt): ?PaymentMethod
    {
        if (! $this->isSpendable($debt)) {
            return null;
        }

        // Already linked — nothing to do.
        $existing = PaymentMethod::query()
            ->where('user_id', $debt->user_id)
            ->where('debt_id', $debt->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // A card method the user already named after this card, e.g. one they
        // added by hand before creating the debt. Restricted to methods that
        // are already card-typed: a debt sharing its name with "Cash" or
        // "Other" must never hijack and retype that entry.
        $byName = PaymentMethod::query()
            ->where('user_id', $debt->user_id)
            ->whereNull('debt_id')
            ->where('type', 'credit_card')
            ->where('name', $debt->name)
            ->first();

        if ($byName !== null) {
            $byName->forceFill(['debt_id' => $debt->id, 'active' => true])->save();

            return $byName;
        }

        // The account starts with a generic "Credit Card" method. The first
        // real card adopts it — renamed to the card — so a one-card user does
        // not end up choosing between "Credit Card" and their card's name.
        $generic = $this->adoptableGenericMethod($debt->user_id);

        if ($generic !== null) {
            $generic->forceFill([
                'name' => $this->availableName($debt->user_id, $debt->name, $generic->id),
                'debt_id' => $debt->id,
                'type' => 'credit_card',
                'active' => true,
            ])->save();

            return $generic;
        }

        return PaymentMethod::create([
            'user_id' => $debt->user_id,
            'name' => $this->availableName($debt->user_id, $debt->name),
            'type' => 'credit_card',
            'icon' => 'credit-card',
            'debt_id' => $debt->id,
            'is_default' => false,
            'active' => true,
            'sort_order' => $this->nextSortOrder($debt->user_id),
        ]);
    }

    /**
     * Follow a rename. Only a method this service is managing — one still named
     * after the card — is renamed, so a name the user chose is left alone.
     */
    public function syncName(Debt $debt, string $previousName): void
    {
        if (! $this->isSpendable($debt) || $debt->name === $previousName) {
            return;
        }

        $method = PaymentMethod::query()
            ->where('user_id', $debt->user_id)
            ->where('debt_id', $debt->id)
            ->where('name', $previousName)
            ->first();

        $method?->forceFill([
            'name' => $this->availableName($debt->user_id, $debt->name, $method->id),
        ])->save();
    }

    /**
     * Called before a debt is deleted.
     *
     * The foreign key nulls the link on its own; an unused method is hidden so
     * the picker does not keep offering a card that no longer exists, while one
     * with expenses is kept so that history still reads correctly.
     */
    public function releaseFor(Debt $debt): void
    {
        $methods = PaymentMethod::query()
            ->where('user_id', $debt->user_id)
            ->where('debt_id', $debt->id)
            ->get();

        foreach ($methods as $method) {
            if ($method->expenses()->exists()) {
                continue;
            }

            $method->forceFill(['active' => false])->save();
        }
    }

    private function isSpendable(Debt $debt): bool
    {
        return in_array($debt->type->value, self::SPENDABLE_TYPES, true);
    }

    /**
     * The seeded generic card method, but only while it is unlinked and has
     * never been used. Anything else is the user's own data.
     */
    private function adoptableGenericMethod(int $userId): ?PaymentMethod
    {
        $generic = PaymentMethod::query()
            ->where('user_id', $userId)
            ->where('type', 'credit_card')
            ->where('name', 'Credit Card')
            ->whereNull('debt_id')
            ->first();

        if ($generic === null || $generic->expenses()->exists()) {
            return null;
        }

        return $generic;
    }

    /**
     * Payment-method names are unique per user, so a card sharing its name with
     * an existing method gets a numbered suffix rather than failing to save.
     */
    private function availableName(int $userId, string $desired, ?int $ignoreId = null): string
    {
        $desired = trim($desired) !== '' ? trim($desired) : 'Credit Card';
        $candidate = $desired;
        $suffix = 2;

        while ($this->nameTaken($userId, $candidate, $ignoreId)) {
            $candidate = $desired.' '.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function nameTaken(int $userId, string $name, ?int $ignoreId): bool
    {
        return PaymentMethod::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function nextSortOrder(int $userId): int
    {
        return (int) PaymentMethod::query()->where('user_id', $userId)->max('sort_order') + 1;
    }

    /**
     * Backfill links for a user whose cards predate this behaviour.
     */
    public function backfillFor(User $user): int
    {
        $linked = 0;

        foreach ($user->debts()->where('type', 'credit_card')->get() as $debt) {
            if ($this->ensureFor($debt) !== null) {
                $linked++;
            }
        }

        return $linked;
    }
}
