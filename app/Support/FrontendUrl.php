<?php

namespace App\Support;

/** Builds links into the separately hosted frontend (configurable base URL and paths). */
final class FrontendUrl
{
    /**
     * @param  array<string, string>  $pathParams
     * @param  array<string, string>  $query
     */
    public static function to(string $page, array $pathParams = [], array $query = []): string
    {
        $path = (string) config("linklibrary.frontend.paths.{$page}", '/');
        foreach ($pathParams as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode($value), $path);
        }

        return config('linklibrary.frontend.url').$path.($query !== [] ? '?'.http_build_query($query) : '');
    }

    public static function link(string $publicId): string
    {
        return self::to('link', ['publicId' => $publicId]);
    }

    public static function collection(string $publicId): string
    {
        return self::to('collection', ['publicId' => $publicId]);
    }

    public static function profile(string $username): string
    {
        return self::to('profile', ['username' => $username]);
    }
}
