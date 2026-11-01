<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()->with('parent')->where('is_active', true)->orderBy('sort_order')->orderBy('slug')->get();

        return ApiResponse::success(CategoryResource::collection($categories)->collection);
    }
}
