<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

/**
 * Standard success envelope: { success: true, message, data, meta? }.
 */
final class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function success(mixed $data = null, ?string $messageKey = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof Paginator) {
            $meta = array_merge(self::paginationMeta($data->resource), $meta);
            $data = $data->collection;
        }
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        } elseif ($data instanceof Collection) {
            $data = $data->map(fn ($item) => $item instanceof JsonResource ? $item->resolve(request()) : $item)->values()->all();
        }

        $payload = [
            'success' => true,
            'message' => $messageKey !== null ? __($messageKey) : null,
            'data' => $data,
        ];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * Paginated list with a per-item mapper (used when items need a context-specific representation).
     *
     * @param  callable(mixed): mixed  $map
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(Paginator $paginator, callable $map, array $meta = []): JsonResponse
    {
        $items = collect($paginator->items())->map(function ($item) use ($map) {
            $mapped = $map($item);

            return $mapped instanceof JsonResource ? $mapped->resolve(request()) : $mapped;
        })->values()->all();

        return new JsonResponse([
            'success' => true,
            'message' => null,
            'data' => $items,
            'meta' => array_merge(self::paginationMeta($paginator), $meta),
        ]);
    }

    public static function created(mixed $data, string $messageKey): JsonResponse
    {
        return self::success($data, $messageKey, 201);
    }

    public static function message(string $messageKey, int $status = 200): JsonResponse
    {
        return self::success(null, $messageKey, $status);
    }

    /** @return array<string, mixed> */
    public static function paginationMeta(Paginator $paginator): array
    {
        $meta = [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
        if ($paginator instanceof LengthAwarePaginator) {
            $meta['pagination']['total'] = $paginator->total();
            $meta['pagination']['last_page'] = $paginator->lastPage();
        }

        return $meta;
    }

    /** Per-page value from the request, clamped to configured bounds. */
    public static function perPage(?int $requested = null): int
    {
        $default = (int) config('linklibrary.pagination.default', 20);
        $max = (int) config('linklibrary.pagination.max', 50);
        $value = $requested ?? (int) request()->integer('per_page', $default);

        return max(1, min($max, $value));
    }
}
