<?php

namespace App\Services\Stock\Handlers;

use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\MovementHandler;
use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\DB;

class TransferHandler implements MovementHandler
{
    public function handle(
        string $tenantId,
        int $productId,
        int $warehouseId,
        int $quantity,
        ?string $reference = null,
        ?array $meta = null
    ): void {
        $fromWarehouseId = $meta['from_warehouse_id'] ?? null;
        $toWarehouseId = $meta['to_warehouse_id'] ?? null;

        if ($fromWarehouseId === null || $toWarehouseId === null) {
            throw new \InvalidArgumentException('Transfer requires from_warehouse_id and to_warehouse_id in meta');
        }

        if ($fromWarehouseId === $toWarehouseId) {
            throw new \InvalidArgumentException('Cannot transfer to the same warehouse');
        }

        // Lock in deterministic order
        $locks = StockLevel::whereIn('warehouse_id', [$fromWarehouseId, $toWarehouseId])
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get();

        if ($locks->count() < 2) {
            throw new \InvalidArgumentException('Missing stock levels for transfer');
        }

        $fromLevel = $locks->firstWhere('warehouse_id', $fromWarehouseId);
        $toLevel = $locks->firstWhere('warehouse_id', $toWarehouseId);

        if ($fromLevel === null || $toLevel === null) {
            throw new \InvalidArgumentException('Missing stock levels for transfer');
        }

        if ($fromLevel->quantity < $quantity) {
            throw new InsufficientStockException('Insufficient stock for transfer');
        }

        DB::transaction(function () use ($tenantId, $productId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $fromLevel, $toLevel) {
            $fromLevel->decrement('quantity', $quantity);
            $toLevel->increment('quantity', $quantity);

            StockMovement::create([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'warehouse_id' => $fromWarehouseId,
                'type' => 'transfer',
                'quantity' => $quantity,
                'reference' => $reference,
                'meta' => [
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                ],
            ]);
        });
    }
}
