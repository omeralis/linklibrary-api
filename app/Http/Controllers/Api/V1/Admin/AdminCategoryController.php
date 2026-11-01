<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** System-managed categories can only be changed by administrators. */
class AdminCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly DiscoveryCache $cache) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(CategoryResource::collection(Category::query()->with('parent')->orderBy('sort_order')->orderBy('slug')->get())->collection);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $category = Category::query()->create($this->mapParent($data));
        $this->changed($request, $category, 'created');

        return ApiResponse::created(new CategoryResource($category->load('parent')), 'api.categories.created');
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->first() ?? throw ApiException::notFound();
        $data = $this->validated($request, $category);
        $category->update($this->mapParent($data));
        $this->changed($request, $category, 'updated');

        return ApiResponse::success(new CategoryResource($category->load('parent')), 'api.categories.updated');
    }

    /** Categories are deactivated rather than deleted, so existing assignments stay meaningful. */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->first() ?? throw ApiException::notFound();
        $category->update(['is_active' => false]);
        $this->changed($request, $category, 'deactivated');

        return ApiResponse::message('api.categories.deactivated');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        $required = $category === null ? 'required' : 'sometimes';

        return $request->validate([
            'slug' => [$required, 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'name_ar' => [$required, 'string', 'max:120'],
            'name_en' => [$required, 'string', 'max:120'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'parent' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'slug'), Rule::notIn([$category?->slug])],
            'sort_order' => ['sometimes', 'integer', 'min:-10000', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapParent(array $data): array
    {
        if (array_key_exists('parent', $data)) {
            $data['parent_id'] = $data['parent'] === null ? null : Category::query()->where('slug', $data['parent'])->value('id');
            unset($data['parent']);
        }

        return $data;
    }

    private function changed(Request $request, Category $category, string $what): void
    {
        $this->cache->invalidate();
        $this->audit->log(AuditAction::CATEGORY_CHANGED, $request->user(), 'category', $category->slug, ['change' => $what]);
    }
}
