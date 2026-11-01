<?php

use Illuminate\Support\Facades\Route;

// API-only backend. The UI is a separate Next.js application.
Route::get('/', fn () => response()->json([
    'name' => config('linklibrary.brand.name'),
    'api' => url('/api/v1'),
    'docs' => url('/docs/openapi.yaml'),
]));

Route::get('/docs/openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']));
