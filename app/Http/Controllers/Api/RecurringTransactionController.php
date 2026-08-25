<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringTransactionRequest;
use App\Http\Resources\RecurringTransactionResource;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecurringTransactionController extends Controller
{
    public function __construct(private readonly RecurringTransactionService $recurring) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $request->user()->recurringTransactions()
            ->with(['category:id,name,icon,color', 'paymentMethod:id,name,icon'])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        // Show what each recurrence actually costs across the coming month,
        // counted from real dates rather than assumed to be monthly.
        $start = CarbonImmutable::today();
        $end = $start->addMonthNoOverflow()->subDay();

        $projected = $items->mapWithKeys(fn (RecurringTransaction $item) => [
            $item->id => [
                'occurrences' => $this->recurring->occurrenceCount($item, $start, $end),
                'projected_total' => $this->recurring->plannedAmountBetween($item, $start, $end),
            ],
        ]);

        return RecurringTransactionResource::collection($items)->additional([
            'meta' => [
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'projected' => $projected,
                'projected_total' => Money::sum($projected->pluck('projected_total')),
            ],
        ]);
    }

    public function store(RecurringTransactionRequest $request): JsonResponse
    {
        $item = $request->user()->recurringTransactions()->create($request->validated());

        return response()->json([
            'data' => new RecurringTransactionResource($item->load(['category', 'paymentMethod'])),
        ], 201);
    }

    public function show(RecurringTransaction $recurringTransaction): JsonResponse
    {
        $this->authorize('view', $recurringTransaction);

        return response()->json([
            'data' => new RecurringTransactionResource(
                $recurringTransaction->load(['category', 'paymentMethod'])
            ),
        ]);
    }

    public function update(RecurringTransactionRequest $request, RecurringTransaction $recurringTransaction): JsonResponse
    {
        $this->authorize('update', $recurringTransaction);

        $recurringTransaction->update($request->validated());

        return response()->json([
            'data' => new RecurringTransactionResource(
                $recurringTransaction->load(['category', 'paymentMethod'])
            ),
        ]);
    }

    public function destroy(RecurringTransaction $recurringTransaction): JsonResponse
    {
        $this->authorize('delete', $recurringTransaction);

        $recurringTransaction->delete();

        return response()->json(['message' => 'Recurring expense deleted.']);
    }
}
