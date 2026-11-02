<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'location',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class,'tenant_id','id');
    }

    public function stockLevels()
    {
        return $this->hasMany(StockLevel::class,'warehouse_id','id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class,'warehouse_id','id');
    }
}
