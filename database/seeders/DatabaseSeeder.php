<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(['subdomain' => 'test'], [
            'name' => 'Test Tenant',
        ]);

        $product = Product::firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'TEST-SKU-001'],
            ['name' => 'Test Product', 'unit_price' => 10.00]
        );

        $warehouse = Warehouse::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Main Warehouse'],
            ['location' => 'Main St']
        );

        StockMovement::firstOrCreate(
            ['tenant_id' => $tenant->id, 'reference' => 'seed-concurrency-test'],
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'in',
                'quantity' => 50,
                'meta' => null,
            ]
        );
    }
}
