<?php

namespace App\Models;

use App\Enums\AllocationType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGoal extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'icon',
        'target_amount',
        'current_amount',
        'monthly_target',
        'allocation_type',
        'allocation_value',
        'target_date',
        'priority',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'monthly_target' => 'decimal:2',
            'allocation_value' => 'decimal:2',
            'allocation_type' => AllocationType::class,
            'target_date' => 'date',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function planAllocations(): HasMany
    {
        return $this->hasMany(PlanSavingsAllocation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function progressPercentage(): float
    {
        return min(100.0, Money::percentage($this->current_amount, $this->target_amount));
    }

    public function remainingAmount(): string
    {
        return Money::floorAtZero(Money::sub($this->target_amount, $this->current_amount));
    }

    public function isReached(): bool
    {
        return Money::gte($this->current_amount, $this->target_amount);
    }
}
