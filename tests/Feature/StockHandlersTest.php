<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Stock\Handlers\InHandler;
use App\Services\Stock\Handlers\OutHandler;
use App\Services\Stock\Handlers\TransferHandler;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Exceptions\InsufficientStockException;

class StockHandlersTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Product $product;
    protected Warehouse $warehouse;
    protected Warehouse $secondWarehouse;
    protected TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'subdomain' => 'test-tenant'. uniqid(),
        ]);

        $this->tenantContext->setTenant($this->tenant);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'unit_price' => 10,
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warehouse A',
        ]);

        $this->secondWarehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warehouse B',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();

        parent::tearDown();
    }

    public function test_stock_increases_after_stock_in(): void
    {
        app(InHandler::class)->handle(
            (string) $this->tenant->id,
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 10,
        ]);
    }

    public function test_stock_decreases_after_stock_out(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        app(OutHandler::class)->handle(
            (string) $this->tenant->id,
            $this->product->id,
            $this->warehouse->id,
            5
        );

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => 5,
        ]);
    }

    public function test_stock_out_fails_when_stock_is_insufficient(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
        ]);

        $this->expectException(InsufficientStockException::class);

        app(OutHandler::class)->handle(
            (string) $this->tenant->id,
            $this->product->id,
            $this->warehouse->id,
            5
        );
    }

    public function test_transfer_moves_stock_between_warehouses(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 20,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->secondWarehouse->id,
            'quantity' => 5,
        ]);

        app(TransferHandler::class)->handle(
            (string) $this->tenant->id,
            $this->product->id,
            $this->warehouse->id,
            7,
            'TRANSFER-001',
            [
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $this->secondWarehouse->id,
            ]
        );

        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 13,
        ]);

        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $this->secondWarehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 12,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'type' => 'transfer',
            'quantity' => 7,
            'reference' => 'TRANSFER-001',
        ]);
    }

    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(TransferHandler::class)->handle(
            (string) $this->tenant->id,
            $this->product->id,
            $this->warehouse->id,
            5,
            null,
            [
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $this->warehouse->id,
            ]
        );
    }

    public function test_stock_in_rejects_product_from_another_tenant(): void
    {
        $anotherTenant = Tenant::create([
            'name' => 'Another Tenant',
            'subdomain' => 'another-tenant',
        ]);

        $anotherProduct = Product::create([
            'tenant_id' => $anotherTenant->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'unit_price' => 20,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(InHandler::class)->handle(
            (string) $this->tenant->id,
            $anotherProduct->id,
            $this->warehouse->id,
            5
        );
    }
}
