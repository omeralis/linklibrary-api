<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['sometimes', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();

        return ApiResponse::success(NotificationResource::collection($query->paginate(ApiResponse::perPage())));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            throw ApiException::notFound();
        }
        $notification = $request->user()->notifications()->whereKey($id)->first() ?? throw ApiException::notFound();
        $notification->markAsRead();

        return ApiResponse::success(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['updated' => $count], 'api.notifications.all_read');
    }
}
