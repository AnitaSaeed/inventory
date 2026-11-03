<?php

namespace App\Console\Commands;

use App\Models\StockLevel;
use Illuminate\Console\Command;

class InventoryReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:reconcile {tenant} {--fix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile stock levels against movements ledger';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $tenantId = $this->argument('tenant');
        $fix = $this->option('fix');

        $products = \App\Models\Product::where('tenant_id', $tenantId)
            ->with(['stockLevels', 'stockMovements'])
            ->get();

        $mismatches = 0;

        foreach ($products as $product) {
            $expected = [];

            // Calculate expected stock from movements
            $movements = $product->stockMovements()
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $wh = (int) $movement->warehouse_id;
                $qty = (int) $movement->quantity;

                if (!isset($expected[$wh])) {
                    $expected[$wh] = 0;
                }

                switch ($movement->type) {
                    case 'in':
                        $expected[$wh] += $qty;
                        break;

                    case 'out':
                        $expected[$wh] -= $qty;
                        break;

                    case 'transfer':
                        $from = (int) ($movement->meta['from_warehouse_id'] ?? $wh);
                        $to = (int) ($movement->meta['to_warehouse_id'] ?? $wh);

                        $expected[$from] = ($expected[$from] ?? 0) - $qty;
                        $expected[$to] = ($expected[$to] ?? 0) + $qty;
                        break;
                }
            }

            foreach ($expected as $warehouseId => $qty) {
                $levelQuery = StockLevel::where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId);

                $level = $fix
                    ? $levelQuery->lockForUpdate()->first()
                    : $levelQuery->first();

                $actual = $level?->quantity ?? 0;

                if ($actual !== $qty) {
                    $mismatches++;
                    $this->warn(sprintf(
                        'MISMATCH: tenant=%s product=%s warehouse=%s expected=%d actual=%d',
                        $tenantId,
                        $product->id,
                        $warehouseId,
                        $qty,
                        $actual
                    ));

                    if ($fix) {
                        if ($level) {
                            $level->forceFill(['quantity' => $qty])->save();
                            $this->info(sprintf('  -> Fixed to %d', $qty));
                        } else {
                            StockLevel::create([
                                'tenant_id' => $tenantId,
                                'product_id' => $product->id,
                                'warehouse_id' => $warehouseId,
                                'quantity' => $qty,
                            ]);
                            $this->info(sprintf('  -> Created with %d', $qty));
                        }
                    }
                }
            }
        }

        if ($mismatches === 0) {
            $this->info('All stock levels are in sync.');
        }

        return $mismatches === 0 ? Command::SUCCESS : Command::FAILURE;
    }

}
