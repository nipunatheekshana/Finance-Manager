<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = $request->user()->categories()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $request->user()->categories()->create($request->validated());

        return response()->json(['data' => new CategoryResource($category)], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        // Categories with history are archived rather than deleted, so past
        // expenses keep a meaningful label.
        if ($category->expenses()->exists()) {
            $category->update(['active' => false]);

            return response()->json([
                'message' => 'This category has expenses, so it has been hidden instead of deleted.',
                'data' => new CategoryResource($category),
            ]);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
