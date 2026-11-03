<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Product $product;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reconcile Tenant',
            'subdomain' => 'reconcile-tenant'. uniqid(),
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'REC-001',
            'name' => 'Reconcile Product',
            'unit_price' => 10,
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reconcile Warehouse',
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'in',
            'quantity' => 10,
            'reference' => 'REC-IN-001',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
        ]);
    }

    public function test_reconcile_command_detects_stock_mismatch(): void
    {
        $this->artisan('inventory:reconcile', [
            'tenant' => $this->tenant->id,
        ])
            ->expectsOutputToContain('MISMATCH')
            ->assertExitCode(1);
    }

    public function test_reconcile_command_fixes_stock_mismatch(): void
    {
        $this->artisan('inventory:reconcile', [
            'tenant' => $this->tenant->id,
            '--fix' => true,
        ]);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);
    }
}
