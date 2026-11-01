<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores user-uploaded images safely: the bytes are decoded and RE-ENCODED with GD (stripping
 * metadata, scripts and polyglot payloads), resized to a bounded size and stored under a random name.
 * SVG and other vector/script-capable formats are never accepted.
 */
class ImageStorage
{
    public function store(UploadedFile $file, string $directory, int $maxDimension = 1600): string
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw ApiException::unprocessable('INVALID_IMAGE', 'api.uploads.invalid_image', ['image' => [__('api.uploads.invalid_image')]]);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ApiException::unprocessable('INVALID_IMAGE', 'api.uploads.invalid_image', ['image' => [__('api.uploads.invalid_image')]]);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxDimension / max($width, $height));
        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            if ($resized !== false) {
                $image = $resized; // the previous GdImage is freed automatically (imagedestroy() is deprecated in PHP 8.5)
            }
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 85);
        $encoded = (string) ob_get_clean();
        unset($image);

        $path = trim($directory, '/').'/'.Str::random(40).'.webp';
        Storage::disk((string) config('linklibrary.uploads.disk'))->put($path, $encoded, ['visibility' => 'public']);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk((string) config('linklibrary.uploads.disk'))->delete($path);
        }
    }
}
