<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'icon',
        'color',
        'monthly_budget',
        'is_allowance',
        'weekly_budget',
        'warning_percentage',
        'is_default',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_budget' => 'decimal:2',
            'is_allowance' => 'boolean',
            'weekly_budget' => 'decimal:2',
            'warning_percentage' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The categories every new account starts with. */
    public const DEFAULTS = [
        ['name' => 'Food', 'icon' => 'utensils', 'color' => 'amber'],
        ['name' => 'Transport', 'icon' => 'car', 'color' => 'sky'],
        ['name' => 'Shopping', 'icon' => 'shopping-bag', 'color' => 'violet'],
        ['name' => 'Entertainment', 'icon' => 'clapperboard', 'color' => 'pink'],
        ['name' => 'Bills', 'icon' => 'receipt', 'color' => 'slate'],
        ['name' => 'Smoking', 'icon' => 'cigarette', 'color' => 'stone'],
        ['name' => 'Personal', 'icon' => 'user', 'color' => 'teal'],
        ['name' => 'Gym', 'icon' => 'dumbbell', 'color' => 'lime'],
        ['name' => 'Health', 'icon' => 'heart-pulse', 'color' => 'rose'],
        ['name' => 'Subscriptions', 'icon' => 'repeat', 'color' => 'indigo'],
        ['name' => 'Family', 'icon' => 'users', 'color' => 'orange'],
        ['name' => 'Other', 'icon' => 'circle-ellipsis', 'color' => 'zinc'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function budgetCategories(): HasMany
    {
        return $this->hasMany(BudgetCategory::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Categories whose budget is reserved in the plan, not just a warning. */
    public function scopeAllowances(Builder $query): Builder
    {
        return $query->where('is_allowance', true)->where('monthly_budget', '>', 0);
    }
}
