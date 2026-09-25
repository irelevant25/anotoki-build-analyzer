<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\BuildController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Support\Facades\Route;

Route::get('/meta', MetaController::class);

Route::get('/accounts', [AccountController::class, 'index']);
Route::get('/accounts/{account}', [AccountController::class, 'show']);
Route::patch('/accounts/{account}', [AccountController::class, 'update']);
Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);
Route::get('/accounts/{account}/imports', [AccountController::class, 'imports']);
Route::get('/accounts/{account}/characters/{key}', [AccountController::class, 'character']);

Route::post('/imports/enka', [ImportController::class, 'enka'])->middleware('throttle:10,1');
Route::post('/imports/file', [ImportController::class, 'file']);

Route::get('/builds/{game}', [BuildController::class, 'characters']);
Route::get('/builds/{game}/{key}', [BuildController::class, 'character']);

Route::get('/sources', [SourceController::class, 'index']);
Route::patch('/sources/{key}', [SourceController::class, 'update']);
Route::post('/sources/{key}/sync', [SourceController::class, 'sync']);
Route::get('/sync-runs', [SourceController::class, 'runs']);
Route::get('/sync-runs/{run}', [SourceController::class, 'showRun']);
