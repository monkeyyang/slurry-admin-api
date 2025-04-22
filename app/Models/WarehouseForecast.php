<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseForecast extends Model
{
    protected $table = 'warehouse_forecast';

    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    // 状态常量
    const STATUS_PENDING = 0;   // 待收货
    const STATUS_RECEIVED = 1;  // 已收货
    const STATUS_CANCELLED = 2; // 已取消
    const STATUS_STORED = 9; // 已入库
    const STATUS_SETTLED = 10; // 已结算

    protected $fillable = [
        'preorder_no',
        'customer_id',
        'customer_name',
        'warehouse_id',
        'warehouse_name',
        'product_name',
        'goods_url',
        'order_number',
        'tracking_no',
        'product_code',
        'quantity',
        'status',
        'create_time',
        'receive_time',
        'update_time',
        'create_user_id',
        'deleted',
        'delete_time'
    ];

    protected $casts = [
        'create_time' => 'datetime',
        'receive_time' => 'datetime',
        'update_time' => 'datetime',
        'delete_time' => 'datetime',
        'quantity' => 'integer',
        'status' => 'integer',
        'deleted' => 'integer'
    ];

    // 获取状态文本
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => '待收货',
            self::STATUS_RECEIVED => '已收货',
            self::STATUS_CANCELLED => '已取消',
            default => '未知状态',
        };
    }

    // 关联仓库
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // 关联客户
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    // 关联创建人
    public function creator()
    {
        return $this->belongsTo(User::class, 'create_user_id');
    }

    // 关联库存
    public function inventory()
    {
        return $this->hasOne(WarehouseInventory::class, 'forecast_id');
    }
}
