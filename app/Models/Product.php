<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class,'tenant_id','id');
    }

    public function stockLevels()
    {
        return $this->hasMany(StockLevel::class,'product_id','id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class,'product_id','id');
    }
}
