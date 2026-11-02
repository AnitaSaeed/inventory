<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'location',
    ];
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

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
