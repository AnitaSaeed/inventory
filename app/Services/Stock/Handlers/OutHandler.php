<?php

namespace App\Services\Stock\Handlers;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\MovementHandler;
use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\DB;

class OutHandler implements MovementHandler
{
    public function handle(
        string $tenantId,
        int $productId,
        int $warehouseId,
        int $quantity,
        ?string $reference = null,
        ?array $meta = null
    ): void {
        $this->validateOwnership($tenantId, $productId, $warehouseId);

        DB::transaction(function () use ($tenantId, $productId, $warehouseId, $quantity, $reference, $meta) {
            // Lock in deterministic order: warehouse_id ASC, product_id ASC
            $level = StockLevel::where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->first();

            if (! $level || $level->quantity < $quantity) {
                throw new InsufficientStockException('Insufficient stock for this product in the specified warehouse');
            }

            $level->forceFill(['quantity' => $level->quantity - $quantity])->save();

            StockMovement::create([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => 'out',
                'quantity' => $quantity,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    protected function validateOwnership(string $tenantId, int $productId, int $warehouseId): void
    {
        if (! Product::where('tenant_id', $tenantId)->where('id', $productId)->exists()) {
            throw new \InvalidArgumentException('Product does not belong to this tenant');
        }
        if (! Warehouse::where('tenant_id', $tenantId)->where('id', $warehouseId)->exists()) {
            throw new \InvalidArgumentException('Warehouse does not belong to this tenant');
        }
    }
}
