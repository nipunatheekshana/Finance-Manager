<?php

namespace App\Models;

use App\Enums\DebtType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Debt extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'original_amount',
        'current_balance',
        'credit_limit',
        'interest_rate',
        'minimum_payment',
        'planned_payment',
        'due_day',
        'remaining_installments',
        'installment_amount',
        'early_settlement_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => DebtType::class,
            'original_amount' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'interest_rate' => 'decimal:3',
            'minimum_payment' => 'decimal:2',
            'planned_payment' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'early_settlement_amount' => 'decimal:2',
            'due_day' => 'integer',
            'remaining_installments' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    /** Expenses charged directly to this debt (e.g. credit-card spending). */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function planAllocations(): HasMany
    {
        return $this->hasMany(PlanDebtAllocation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isCreditCard(): bool
    {
        return $this->type === DebtType::CreditCard;
    }

    public function isInstallment(): bool
    {
        return $this->type === DebtType::Installment;
    }

    /**
     * How much of the original balance has been cleared, 0-100.
     * Returns 0 when there is no original amount to measure against.
     */
    public function progressPercentage(): float
    {
        if (Money::isZero($this->original_amount)) {
            return 0.0;
        }

        $paidOff = Money::floorAtZero(Money::sub($this->original_amount, $this->current_balance));

        return min(100.0, Money::percentage($paidOff, $this->original_amount));
    }

    /** Credit utilisation, or null when the debt has no limit. */
    public function utilisationPercentage(): ?float
    {
        if ($this->credit_limit === null || Money::isZero($this->credit_limit)) {
            return null;
        }

        return Money::percentage($this->current_balance, $this->credit_limit);
    }

    /**
     * The remaining amount according to the installment schedule. This is a
     * schedule total, not a settlement quote.
     */
    public function scheduledRemaining(): ?string
    {
        if (! $this->isInstallment() || ! $this->remaining_installments) {
            return null;
        }

        $perInstallment = $this->installment_amount ?? $this->planned_payment;

        return Money::mul($perInstallment, (string) $this->remaining_installments);
    }
}
