<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->get('/test-tenant', function () {
    return \App\Models\Product::all();
});

