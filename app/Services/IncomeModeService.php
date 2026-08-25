<?php

namespace App\Services;

use App\Enums\CycleAnchor;
use App\Enums\FundingMethod;
use App\Enums\IncomeCadence;
use App\Enums\IncomeMode;
use App\Enums\IncomeSourceKind;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changing how you earn.
 *
 * People do not stay in one category: an employee starts a business, a business
 * owner takes a job, plenty of people do both. Switching has to be safe, which
 * means three rules:
 *
 *   1. Finished cycles are never rewritten. Each plan records how it was
 *      funded, so old months keep reading correctly.
 *   2. A change of cycle anchor only takes effect at the next cycle boundary.
 *      Moving the boundaries under a live plan would invalidate the weekly
 *      budgets someone is currently spending against.
 *   3. Income sources are archived, never deleted, so history survives.
 */
class IncomeModeService
{
    public function __construct(
        private readonly BudgetCycleService $cycles,
        private readonly IncomeForecastService $income,
    ) {}

    /**
     * What would change, without changing it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function preview(User $user, IncomeMode $mode, array $overrides = []): array
    {
        $profile = $user->financialProfile;

        $anchor = isset($overrides['cycle_anchor'])
            ? CycleAnchor::from($overrides['cycle_anchor'])
            : $mode->defaultCycleAnchor();

        $funding = isset($overrides['funding_method'])
            ? FundingMethod::from($overrides['funding_method'])
            : $mode->defaultFundingMethod();

        $anchorChanges = $anchor !== $profile->cycle_anchor;
        $activePlan = $user->monthlyPlans()->active()->first();

        // A live plan pins the boundaries until its cycle ends.
        $effectiveFrom = $anchorChanges && $activePlan !== null
            ? CarbonImmutable::instance($activePlan->cycle_end_date)->addDay()
            : CarbonImmutable::today();

        return [
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'from' => [
                'mode' => $profile->income_mode->value,
                'cycle_anchor' => $profile->cycle_anchor->value,
                'funding_method' => $profile->funding_method->value,
            ],
            'to' => [
                'cycle_anchor' => $anchor->value,
                'cycle_anchor_label' => $anchor->label(),
                'funding_method' => $funding->value,
                'funding_method_label' => $funding->label(),
            ],
            'cycle_anchor_changes' => $anchorChanges,
            'takes_effect_on' => $effectiveFrom->toDateString(),
            'deferred' => $anchorChanges && $activePlan !== null,
            'active_plan_label' => $activePlan?->label(),
            'needs_salary' => $mode->hasSalary(),
            'needs_draw' => $funding->usesHoldingPot(),
            'suggested_draw' => $funding->usesHoldingPot()
                ? $this->income->suggestedDraw($user)
                : null,
            'history_preserved' => true,
        ];
    }

    /**
     * Apply the change.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function apply(User $user, IncomeMode $mode, array $settings = []): array
    {
        $preview = $this->preview($user, $mode, $settings);

        return DB::transaction(function () use ($user, $mode, $settings, $preview) {
            $profile = $user->financialProfile;

            $profile->applyIncomeMode($mode, $settings);

            foreach (['base_salary', 'target_draw', 'cycle_start_day', 'forecast_months', 'forecast_factor'] as $field) {
                if (array_key_exists($field, $settings) && $settings[$field] !== null) {
                    $profile->{$field} = in_array($field, ['base_salary', 'target_draw'], true)
                        ? Money::of($settings[$field])
                        : (int) $settings[$field];
                }
            }

            // A mode without a salary should not keep quoting one.
            if (! $mode->hasSalary()) {
                $profile->base_salary = '0.00';
            }

            $profile->save();

            $this->syncIncomeSources($user, $mode);

            return $preview + ['applied' => true];
        });
    }

    /**
     * Keep income sources in step with the mode: make sure the ones the mode
     * needs exist, and archive the ones it no longer does.
     */
    private function syncIncomeSources(User $user, IncomeMode $mode): void
    {
        if ($mode->hasSalary()) {
            $user->incomeSources()->updateOrCreate(
                ['kind' => IncomeSourceKind::Salary->value],
                [
                    'name' => 'Salary',
                    'type' => 'salary',
                    'cadence' => IncomeCadence::Monthly->value,
                    'expected_amount' => Money::of($user->financialProfile->base_salary),
                    'active' => true,
                    'archived_at' => null,
                ],
            );
        } else {
            // Archived rather than deleted: past income keeps its source.
            $user->incomeSources()
                ->where('kind', IncomeSourceKind::Salary->value)
                ->whereNull('archived_at')
                ->update(['active' => false, 'archived_at' => now()]);
        }

        if ($mode->hasIrregularIncome() && ! $user->incomeSources()
            ->whereIn('kind', [
                IncomeSourceKind::Client->value,
                IncomeSourceKind::Project->value,
                IncomeSourceKind::Business->value,
            ])
            ->exists()) {
            $user->incomeSources()->create([
                'name' => $mode === IncomeMode::Business ? 'Business takings' : 'Freelance work',
                'type' => 'other',
                'kind' => $mode === IncomeMode::Business
                    ? IncomeSourceKind::Business->value
                    : IncomeSourceKind::Client->value,
                'cadence' => $mode === IncomeMode::Business
                    ? IncomeCadence::Daily->value
                    : IncomeCadence::PerProject->value,
                'expected_amount' => '0.00',
                'active' => true,
            ]);
        }
    }

    /**
     * The four modes, for the onboarding and settings pickers.
     *
     * @return list<array<string, mixed>>
     */
    public function availableModes(): array
    {
        return array_map(fn (IncomeMode $mode) => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'description' => $mode->description(),
            'default_cycle_anchor' => $mode->defaultCycleAnchor()->value,
            'default_funding_method' => $mode->defaultFundingMethod()->value,
            'has_salary' => $mode->hasSalary(),
            'has_irregular_income' => $mode->hasIrregularIncome(),
        ], IncomeMode::cases());
    }

    /**
     * Funding methods that make sense for a mode, so the picker never offers a
     * combination that cannot work.
     *
     * @return list<array<string, mixed>>
     */
    public function fundingMethodsFor(IncomeMode $mode): array
    {
        $allowed = match ($mode) {
            IncomeMode::Salaried => [FundingMethod::Fixed],
            IncomeMode::SelfEmployed, IncomeMode::Business => [
                FundingMethod::Draw,
                FundingMethod::Forecast,
                FundingMethod::Actual,
            ],
            IncomeMode::Hybrid => [
                FundingMethod::SalaryPlusDraw,
                FundingMethod::Fixed,
                FundingMethod::Forecast,
            ],
        };

        return array_map(fn (FundingMethod $method) => [
            'value' => $method->value,
            'label' => $method->label(),
            'description' => $method->description(),
            'recommended' => $method === $mode->defaultFundingMethod(),
            'uses_holding_pot' => $method->usesHoldingPot(),
        ], $allowed);
    }
}
