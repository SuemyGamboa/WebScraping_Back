<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductoController;

Route::get('/productos', [ProductoController::class, 'index']);
Route::get('/productos/{id}/historial', [ProductoController::class, 'historial']);
Route::get('/productos/{id}/preview', [ProductoController::class, 'preview']);
Route::get('/productos/{id}', [ProductoController::class, 'show']);
Route::post('/productos', [ProductoController::class, 'store']);
Route::patch('/productos/{id}', [ProductoController::class, 'update']);
Route::delete('/productos/{id}', [ProductoController::class, 'destroy']);