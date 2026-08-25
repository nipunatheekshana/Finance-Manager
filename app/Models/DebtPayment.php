<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtPayment extends Model
{
    protected $fillable = [
        'debt_id',
        'monthly_plan_id',
        'amount',
        'payment_date',
        'interest_amount',
        'principal_amount',
        'balance_after',
        'reduced_installment',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payment_date' => 'date',
            'reduced_installment' => 'boolean',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }
}
