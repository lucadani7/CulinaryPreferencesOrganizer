<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Swagger UI for the REST API (static files in public/docs/)
Route::redirect('/docs', '/docs/index.html');
