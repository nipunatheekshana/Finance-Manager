<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpensePreviewRequest;
use App\Http\Requests\ExpenseRequest;
use App\Http\Requests\SyncExpensesRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseImpactService;
use App\Services\ExpenseService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpenseImpactService $impact,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'min_amount' => ['nullable', 'numeric'],
            'max_amount' => ['nullable', 'numeric'],
            'sort' => ['nullable', 'in:date_desc,date_asc,amount_desc,amount_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->expenses()
            ->with(['category:id,name,icon,color', 'paymentMethod:id,name,icon']);

        if ($search = ($validated['search'] ?? null)) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%'.$search.'%')
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', '%'.$search.'%'));
            });
        }

        $query
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['payment_method_id'] ?? null, fn ($q, $id) => $q->where('payment_method_id', $id))
            ->when($validated['from'] ?? null, fn ($q, $date) => $q->whereDate('expense_date', '>=', $date))
            ->when($validated['to'] ?? null, fn ($q, $date) => $q->whereDate('expense_date', '<=', $date))
            ->when($validated['min_amount'] ?? null, fn ($q, $amount) => $q->where('amount', '>=', $amount))
            ->when($validated['max_amount'] ?? null, fn ($q, $amount) => $q->where('amount', '<=', $amount));

        match ($validated['sort'] ?? 'date_desc') {
            'date_asc' => $query->orderBy('expense_date')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('amount'),
            'amount_asc' => $query->orderBy('amount'),
            default => $query->orderByDesc('expense_date')->orderByDesc('id'),
        };

        // Total across the whole filtered set, not just this page. Taken before
        // paginating so the pagination count query cannot interfere.
        $filteredTotal = Money::of((clone $query)->toBase()->sum('amount'));

        $paginator = $query->paginate($validated['per_page'] ?? 25)->withQueryString();

        return ExpenseResource::collection($paginator)->additional([
            'meta' => ['filtered_total' => $filteredTotal],
        ]);
    }

    /**
     * What an expense would do to the week, month and category budgets, without
     * saving anything. Lets the form warn before the money is committed.
     */
    public function preview(ExpensePreviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->impact->preview(
                $request->user(),
                $validated['amount'],
                $validated['expense_date'] ?? null,
                $validated['category_id'] ?? null,
                $validated['expense_id'] ?? null,
            ),
        ]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->create($request->user(), $request->validated());

        return response()->json([
            'data' => new ExpenseResource($expense),
            // Tells the client whether this expense tipped the week over, so
            // the choice can be put in front of the user straight away.
            'week' => $this->impact->weekStateAfter(
                $request->user(),
                $expense->expense_date->toDateString(),
            ),
        ], 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return response()->json([
            'data' => new ExpenseResource($expense->load(['category', 'paymentMethod'])),
        ]);
    }

    public function update(ExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $updated = $this->expenses->update($expense, $request->validated());

        return response()->json([
            'data' => new ExpenseResource($updated),
            'week' => $this->impact->weekStateAfter(
                $request->user(),
                $updated->expense_date->toDateString(),
            ),
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $this->expenses->delete($expense);

        return response()->json(['message' => 'Expense deleted.']);
    }

    /**
     * Flush a queue of expenses recorded while the device was offline.
     */
    public function sync(SyncExpensesRequest $request): JsonResponse
    {
        $result = $this->expenses->syncOffline($request->user(), $request->validated()['expenses']);

        return response()->json([
            'synced' => ExpenseResource::collection($result['synced'])->resolve(),
            'failed' => $result['failed'],
        ]);
    }
}
