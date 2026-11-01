<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Non-enumerable public identifier (random UUIDv4). Public identifiers locate a resource but never
 * grant access to it: every lookup is followed by a policy check.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Guard route-model binding: malformed identifiers resolve to "not found" instead of reaching
     * PostgreSQL's uuid parser.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (($field === null || $field === 'public_id') && ! self::isValidPublicId(is_string($value) ? $value : null)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public static function isValidPublicId(?string $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }
}
