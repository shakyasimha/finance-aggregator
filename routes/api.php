<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\ArticleController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::get('/sources', [SourceController::class, 'index']);     // GET request
Route::get('/sources', [SourceController::class, 'store']);     // POST request
Route::get('/articles', [ArticleController::class, 'index']);   // GET request