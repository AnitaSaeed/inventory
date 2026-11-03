<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\Request;

class ProductHistoryController extends Controller
{
    public function __invoke(string $sku)
    {
        $tenantId = app(\App\Support\TenantContext::class)->current();

        $movements = StockMovement::where('tenant_id', $tenantId)
            ->whereHas('product', function ($q) use ($sku) {
                $q->where('sku', $sku);
            })
            ->with('product')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($movements);
    }
}
