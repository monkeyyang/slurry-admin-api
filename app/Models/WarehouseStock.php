<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStock extends Model
{
    protected $table = 'warehouse_stock';
    
    protected $fillable = [
        'warehouse_id',
        'forecast_id',
        'goods_name',
        'tracking_no',
        'product_code',
        'status',
        'storage_time',
        'settle_time',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'storage_time' => 'datetime',
        'settle_time' => 'datetime',
    ];

    // 关联仓库
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // 关联预报
    public function forecast()
    {
        return $this->belongsTo(WarehouseForecast::class, 'forecast_id');
    }
} 