<?php

namespace App\Http\Controllers;

use App\Models\StockLevel;
use Illuminate\Http\Request;

class StockLevelController extends Controller
{
    public function index()
    {
        $tenantId = app(\App\Support\TenantContext::class)->current();
        $query = StockLevel::where('tenant_id', $tenantId);

        if ($productId = request()->query('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($warehouseId = request()->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        $levels = $query->paginate(25);

        return response()->json($levels);
    }
}
