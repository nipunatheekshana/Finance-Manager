<?php

namespace App\Models;

use App\Enums\IncomeCadence;
use App\Enums\IncomeSourceKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomeSource extends Model
{
    protected $fillable = [
        'user_id', 'name', 'type', 'kind', 'cadence',
        'client_name', 'expected_amount', 'active', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'kind' => IncomeSourceKind::class,
            'cadence' => IncomeCadence::class,
            'active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(IncomeTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true)->whereNull('archived_at');
    }

    /** Salary is funded directly; everything else flows through the pot. */
    public function isSalary(): bool
    {
        return $this->kind === IncomeSourceKind::Salary;
    }
}
