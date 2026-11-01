<?php

namespace App\Http\Requests;

class ImageUploadRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Raster formats only (no SVG: scripts), size-limited; content is re-validated by ImageStorage.
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:'.config('linklibrary.uploads.max_image_kb'), 'dimensions:max_width=8000,max_height=8000'],
        ];
    }
}
