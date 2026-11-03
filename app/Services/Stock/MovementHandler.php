<?php

namespace App\Services\Stock;

use App\Models\StockLevel;
use App\Exceptions\InsufficientStockException;

interface MovementHandler
{
    public function handle(
        string $tenantId,
        int $productId,
        int $warehouseId,
        int $quantity,
        ?string $reference = null,
        ?array $meta = null
    ): void;
}
