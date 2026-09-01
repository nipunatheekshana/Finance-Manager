<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'handle', 'avatar_path'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * A handle is how the account is referred to: lowercase, unique, and
     * stable enough to appear in a URL one day.
     */
    public const HANDLE_PATTERN = '/^[a-z0-9](?:[a-z0-9_.]{1,28}[a-z0-9])$/';

    /** Names that would collide with a route or read as official. */
    public const RESERVED_HANDLES = [
        'admin', 'administrator', 'api', 'app', 'auth', 'budget', 'dashboard',
        'debts', 'expenses', 'help', 'income', 'login', 'logout', 'me', 'plan',
        'profile', 'register', 'reports', 'root', 'savings', 'settings',
        'support', 'system', 'user', 'users',
    ];

    protected static function booted(): void
    {
        // Everyone has a handle from the moment the account exists, so nothing
        // downstream has to cope with it being missing.
        static::creating(function (self $user) {
            $user->handle ??= self::generateHandle(
                $user->name ?: \Illuminate\Support\Str::before((string) $user->email, '@')
            );
        });
    }

    /** A handle derived from a name, made unique with a numeric suffix. */
    public static function generateHandle(string $from): string
    {
        $base = \Illuminate\Support\Str::of($from)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->limit(24, '')
            ->value();

        if (strlen($base) < 3 || in_array($base, self::RESERVED_HANDLES, true)) {
            $base = 'user'.substr((string) $base, 0, 20);
        }

        $handle = $base;
        $suffix = 1;

        while (self::query()->where('handle', $handle)->exists()) {
            $handle = $base.(++$suffix);
        }

        return $handle;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path);
    }

    /** The two letters shown when there is no picture. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: mb_strtoupper(mb_substr($this->email, 0, 1));
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(FinancialProfile::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function monthlyPlans(): HasMany
    {
        return $this->hasMany(MonthlyPlan::class);
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }

    public function incomeTransactions(): HasMany
    {
        return $this->hasMany(IncomeTransaction::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(FinancialAlert::class);
    }

    public function budgetAdjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
