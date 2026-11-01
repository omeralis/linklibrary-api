<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Media\ImageStorage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly ImageStorage $images) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->accounts->updateProfile($request->user(), $request->validated());

        return ApiResponse::success(new UserResource($user), 'api.profile.updated');
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->accounts->changePassword(
            $user,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request->hasSession() ? $request->session()->getId() : null,
            $user->currentPersonalAccessTokenId(),
        );

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return ApiResponse::message('api.profile.password_updated');
    }

    public function uploadAvatar(ImageUploadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $path = $this->images->store($request->file('image'), 'avatars', 512);
        $this->images->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => $path])->save();

        return ApiResponse::success(new UserResource($user), 'api.profile.updated');
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->images->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null, 'avatar_url' => null])->save();

        return ApiResponse::success(new UserResource($user), 'api.profile.updated');
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->accounts->deleteAccount($user, $request->string('password')->toString());

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::message('api.profile.deleted');
    }
}
