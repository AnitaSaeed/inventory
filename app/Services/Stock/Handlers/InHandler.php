<?php

namespace App\Services\Stock\Handlers;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\MovementHandler;
use Illuminate\Support\Facades\DB;

class InHandler implements MovementHandler
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
            $level = StockLevel::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                ],
                ['quantity' => 0]
            );

            $level->forceFill([
                'quantity' => $level->quantity + $quantity,
            ])->save();

            StockMovement::create([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => 'in',
                'quantity' => $quantity,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    protected function validateOwnership(string $tenantId, int $productId, int $warehouseId): void
    {
        $exists = Product::where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->exists() && Warehouse::where('tenant_id', $tenantId)
            ->where('id', $warehouseId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException('Product or warehouse does not belong to this tenant');
        }
    }
}
