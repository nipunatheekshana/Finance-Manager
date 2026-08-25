<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncomeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncomeSourceRequest;
use App\Http\Requests\IncomeTransactionRequest;
use App\Http\Resources\IncomeSourceResource;
use App\Http\Resources\IncomeTransactionResource;
use App\Models\IncomeSource;
use App\Models\IncomeTransaction;
use App\Services\BudgetCycleService;
use App\Services\FinancialPlanService;
use App\Services\IncomeForecastService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncomeController extends Controller
{
    public function __construct(
        private readonly IncomeForecastService $income,
        private readonly FinancialPlanService $plans,
        private readonly BudgetCycleService $cycles,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:expected,invoiced,received'],
            'income_source_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $profile = $this->plans->profileFor($user);
        $period = $this->cycles->currentPeriodFor($profile);
        [$start, $end] = $this->cycles->cycleFor($period['year'], $period['month'], $profile);

        $transactions = $user->incomeTransactions()
            ->with('incomeSource:id,name,kind')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['income_source_id'] ?? null, fn ($q, $id) => $q->where('income_source_id', $id))
            ->orderByRaw('COALESCE(received_date, due_date) DESC')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return IncomeTransactionResource::collection($transactions)->additional([
            'meta' => [
                'cycle' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'summary' => $this->income->summaryBetween($user, $start, $end),
                'funding' => $this->income->fundingFor($user, $period['year'], $period['month'], $profile),
                'holding_pot' => $profile->funding_method->usesHoldingPot()
                    ? $this->income->runway($user)
                    : null,
                'sources' => IncomeSourceResource::collection(
                    $user->incomeSources()->orderBy('name')->get()
                )->resolve(),
            ],
        ]);
    }

    public function store(IncomeTransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? IncomeStatus::Received->value;

        $transaction = $request->user()->incomeTransactions()->create([
            ...$validated,
            'amount' => Money::of($validated['amount']),
            'status' => $status,
            'type' => $validated['type'] ?? 'base',
            'received_date' => $validated['received_date'] ?? CarbonImmutable::today()->toDateString(),
            'monthly_plan_id' => $this->planIdFor($request, $validated['received_date'] ?? null),
        ]);

        return response()->json([
            'data' => new IncomeTransactionResource($transaction->load('incomeSource')),
            'holding_pot' => $this->income->runway($request->user()),
        ], 201);
    }

    public function update(IncomeTransactionRequest $request, IncomeTransaction $income): JsonResponse
    {
        $this->authorize('update', $income);

        $validated = $request->validated();

        if (array_key_exists('amount', $validated)) {
            $validated['amount'] = Money::of($validated['amount']);
        }

        $income->update($validated);

        return response()->json([
            'data' => new IncomeTransactionResource($income->fresh('incomeSource')),
            'holding_pot' => $this->income->runway($request->user()),
        ]);
    }

    /**
     * Mark an invoice paid. Until this happens the money is not spendable.
     */
    public function markReceived(Request $request, IncomeTransaction $income): JsonResponse
    {
        $this->authorize('update', $income);

        $validated = $request->validate([
            'received_date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        $income->forceFill([
            'status' => IncomeStatus::Received->value,
            'received_date' => $validated['received_date'] ?? CarbonImmutable::today()->toDateString(),
            'amount' => isset($validated['amount']) ? Money::of($validated['amount']) : $income->amount,
        ])->save();

        return response()->json([
            'data' => new IncomeTransactionResource($income->fresh('incomeSource')),
            'holding_pot' => $this->income->runway($request->user()),
        ]);
    }

    public function destroy(IncomeTransaction $income): JsonResponse
    {
        $this->authorize('delete', $income);

        $income->delete();

        return response()->json(['message' => 'Income entry removed.']);
    }

    /**
     * Forecast, history and runway, for the income screen and settings.
     */
    public function forecast(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->plans->profileFor($user);
        $period = $this->cycles->currentPeriodFor($profile);

        return response()->json([
            'data' => [
                'funding' => $this->income->fundingFor($user, $period['year'], $period['month'], $profile),
                'history' => $this->income->rollingAverage($user),
                'suggested_draw' => $this->income->suggestedDraw($user),
                'holding_pot' => $this->income->runway($user),
            ],
        ]);
    }

    // ── Income sources ────────────────────────────────────────────────────

    public function sources(Request $request): AnonymousResourceCollection
    {
        return IncomeSourceResource::collection(
            $request->user()->incomeSources()->orderBy('name')->get()
        );
    }

    public function storeSource(IncomeSourceRequest $request): JsonResponse
    {
        $source = $request->user()->incomeSources()->create($request->validated() + ['type' => 'other']);

        return response()->json(['data' => new IncomeSourceResource($source)], 201);
    }

    public function updateSource(IncomeSourceRequest $request, IncomeSource $incomeSource): JsonResponse
    {
        $this->authorize('update', $incomeSource);

        $incomeSource->update($request->validated());

        return response()->json(['data' => new IncomeSourceResource($incomeSource)]);
    }

    public function destroySource(IncomeSource $incomeSource): JsonResponse
    {
        $this->authorize('delete', $incomeSource);

        // Sources with income are archived so the history keeps its label.
        if ($incomeSource->transactions()->exists()) {
            $incomeSource->forceFill(['active' => false, 'archived_at' => now()])->save();

            return response()->json([
                'message' => 'This source has income recorded against it, so it has been archived.',
                'data' => new IncomeSourceResource($incomeSource),
            ]);
        }

        $incomeSource->delete();

        return response()->json(['message' => 'Income source removed.']);
    }

    private function planIdFor(Request $request, ?string $date): ?int
    {
        $on = CarbonImmutable::parse($date ?? CarbonImmutable::today())->toDateString();

        return $request->user()->monthlyPlans()
            ->whereDate('cycle_start_date', '<=', $on)
            ->whereDate('cycle_end_date', '>=', $on)
            ->value('id');
    }
}
