<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class RecordStockMovementService
{
    public function execute(
        string $tenantId,
        string $type,
        int $productId,
        int $warehouseId,
        int $quantity,
        ?string $reference = null,
        ?array $meta = null
    ): StockMovement {
        $handler = MovementHandlerFactory::make($type);

        $handler->handle(
            tenantId: $tenantId,
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $quantity,
            reference: $reference,
            meta: $meta
        );

        return StockMovement::where('tenant_id', $tenantId)
            ->latest('id')
            ->first();
    }
}
