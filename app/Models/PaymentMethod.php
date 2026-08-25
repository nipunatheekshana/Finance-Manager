<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'icon',
        'debt_id',
        'is_default',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const DEFAULTS = [
        ['name' => 'Cash', 'type' => 'cash', 'icon' => 'banknote'],
        ['name' => 'Bank Account', 'type' => 'bank', 'icon' => 'landmark'],
        ['name' => 'Debit Card', 'type' => 'debit_card', 'icon' => 'credit-card'],
        ['name' => 'Credit Card', 'type' => 'credit_card', 'icon' => 'credit-card'],
        ['name' => 'Koko', 'type' => 'bnpl', 'icon' => 'split'],
        ['name' => 'Other', 'type' => 'other', 'icon' => 'wallet'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** When linked, spending on this method adds to the debt balance. */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
