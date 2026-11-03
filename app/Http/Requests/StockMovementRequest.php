<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'type' => ['required', 'in:in,out,transfer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],

            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('tenant_id', $tenantId),
            ],

            'meta' => ['nullable', 'array'],

            'meta.from_warehouse_id' => [
                'required_if:type,transfer',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('tenant_id', $tenantId),
            ],

            'meta.to_warehouse_id' => [
                'required_if:type,transfer',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('tenant_id', $tenantId),
            ],
        ];
    }
}
