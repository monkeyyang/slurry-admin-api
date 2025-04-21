<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockImportRequest extends FormRequest
{
    public function rules()
    {
        return [
            'warehouseId' => 'required|integer',
            'items' => 'required|array',
            'items.*.goodsName' => 'required|string|max:255',
            'items.*.trackingNo' => 'required|string|max:100',
            'items.*.productCode' => 'nullable|string|max:100',
        ];
    }
} 