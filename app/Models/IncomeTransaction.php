<?php

namespace App\Models;

use App\Enums\IncomeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'income_source_id',
        'monthly_plan_id',
        'amount',
        'received_date',
        'due_date',
        'type',
        'status',
        'description',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => IncomeStatus::class,
            'received_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    /** Only money actually in the bank. */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', IncomeStatus::Received->value);
    }

    /** Billed but not yet paid. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IncomeStatus::Expected->value,
            IncomeStatus::Invoiced->value,
        ]);
    }
}
