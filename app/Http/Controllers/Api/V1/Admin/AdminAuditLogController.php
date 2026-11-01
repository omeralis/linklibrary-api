<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['sometimes', 'string', 'max:60'],
            'target_type' => ['sometimes', 'string', 'max:40'],
            'target_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $page = AuditLog::query()->with('actor')
            ->when(isset($data['action']), fn ($q) => $q->where('action', $data['action']))
            ->when(isset($data['target_type']), fn ($q) => $q->where('target_type', $data['target_type']))
            ->when(isset($data['target_id']), fn ($q) => $q->where('target_id', $data['target_id']))
            ->latest('id')
            ->paginate(ApiResponse::perPage());

        return ApiResponse::success(AuditLogResource::collection($page));
    }
}
