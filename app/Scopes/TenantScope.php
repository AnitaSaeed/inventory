<?php

namespace App\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $tenantId
        );
    }
}
