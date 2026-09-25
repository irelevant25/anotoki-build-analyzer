<?php

use Illuminate\Support\Facades\Route;

// The Angular app, when built into public/app (npm run build in /frontend). During development the
// Angular dev server (http://localhost:4200) is used instead and proxies /api here.
Route::get('/', function () {
    return is_file(public_path('app/index.html'))
        ? redirect('/app/')
        : response()->json(['name' => config('app.name'), 'api' => url('/api/meta'), 'frontend' => 'Run the Angular dev server (see README.md).']);
});

Route::get('/app/{path?}', fn () => response()->file(public_path('app/index.html')))
    ->where('path', '.*');
