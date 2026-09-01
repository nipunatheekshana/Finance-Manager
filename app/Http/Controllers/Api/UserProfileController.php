<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's own page: who it is, and everything it has done.
 *
 * Distinct from ProfileController, which owns the *financial* profile — the
 * salary, cycle day and funding settings that drive the planner.
 */
class UserProfileController extends Controller
{
    public function __construct(private readonly UserProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user->load('financialProfile')),
            'data' => $this->profiles->overview($user),
        ]);
    }

    /** Paginated separately, so the profile itself stays quick to open. */
    public function activity(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profiles->activity(
                $request->user(),
                min(100, max(10, $request->integer('per_page', 30))),
            ),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->validated())->save();

        return response()->json([
            'user' => new UserResource($user->fresh()->load('financialProfile')),
        ]);
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->profiles->setAvatar($user, $request->file('avatar'));

        return response()->json([
            'user' => new UserResource($user->fresh()->load('financialProfile')),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->profiles->removeAvatar($user);

        return response()->json([
            'user' => new UserResource($user->fresh()->load('financialProfile')),
        ]);
    }
}
