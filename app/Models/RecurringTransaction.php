<?php

namespace App\Models;

use App\Enums\AmountType;
use App\Enums\Frequency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'amount',
        'minimum_amount',
        'maximum_amount',
        'amount_type',
        'category_id',
        'payment_method_id',
        'frequency',
        'due_day',
        'day_of_week',
        'interval_days',
        'start_date',
        'end_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'amount_type' => AmountType::class,
            'frequency' => Frequency::class,
            'due_day' => 'integer',
            'day_of_week' => 'integer',
            'interval_days' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Whether this recurrence is live at any point inside the given window. */
    public function isLiveBetween(CarbonInterface $start, CarbonInterface $end): bool
    {
        if ($this->start_date->gt($end)) {
            return false;
        }

        return $this->end_date === null || $this->end_date->gte($start);
    }
}
