<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->get('/test-tenant', function () {
    return \App\Models\Product::all();
});
Route::middleware(['api', \App\Http\Middleware\ResolveTenant::class, \App\Http\Middleware\GlobalTenantScope::class])->group(function () {
    Route::get('/stock-levels', [\App\Http\Controllers\StockLevelController::class, 'index']);
});
