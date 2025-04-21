<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseInventory extends Model
{
    protected $table = 'warehouse_inventory';
    
    // 定义状态常量
    const STATUS_PENDING = 1;  // 待入库
    const STATUS_STORED = 2;   // 已入库
    const STATUS_SETTLED = 3;  // 已结算
    // 如果将来需要添加新状态，直接在这里添加常量即可
    // const STATUS_RETURNED = 4;  // 已退回
    // const STATUS_DAMAGED = 5;   // 已损坏
    
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

    // 获取状态文本
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => '待入库',
            self::STATUS_STORED => '已入库',
            self::STATUS_SETTLED => '已结算',
            default => '未知状态',
        };
    }
} 