<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMovementRequest;
use App\Services\Stock\RecordStockMovementService;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __invoke(StockMovementRequest $request, RecordStockMovementService $service)
    {
        $data = $request->validated();

        try {
            $movement = $service->execute(
                tenantId: app(\App\Support\TenantContext::class)->current(),
                type: $data['type'],
                productId: $data['product_id'],
                warehouseId: $data['warehouse_id'],
                quantity: $data['quantity'],
                reference: $data['reference'] ?? null,
                meta: $data['meta'] ?? null,
            );

            return response()->json([
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'created_at' => $movement->created_at,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
