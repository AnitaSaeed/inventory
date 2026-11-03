<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->unique(
                ['tenant_id', 'product_id', 'warehouse_id'],
                'stock_levels_tenant_product_warehouse_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropUnique('stock_levels_tenant_product_warehouse_unique');
        });
    }
};
