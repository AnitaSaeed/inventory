<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'subdomain',
    ];

    public function products()
    {
        return $this->hasMany(Product::class,'tenant_id','id');
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class,'tenant_id','id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class,'tenant_id','id');
    }

    public function stockLevels()
    {
        return $this->hasMany(StockLevel::class,'tenant_id','id');
    }
}
