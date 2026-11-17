<?php

use Illuminate\Support\Facades\Route;

// The user interface is the React app in frontend/; the backend only serves the API and its docs.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api'),
    'docs' => url('/docs'),
]));

// Swagger UI for the REST API (static files in public/docs/)
Route::redirect('/docs', '/docs/index.html');
