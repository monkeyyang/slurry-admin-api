<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseInventory extends Model
{
    protected $table = 'warehouse_inventory';
    
    protected $fillable = [
        'warehouse_id',
        'forecast_id',
        'goods_name',
        'tracking_no',
        'product_code',
        'status',
        'storage_time',
        'settle_time',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'deleted'
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'forecast_id' => 'integer',
        'status' => 'integer',
        'storage_time' => 'datetime',
        'settle_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted' => 'boolean'
    ];

    // 状态常量
    const STATUS_PENDING = 1;  // 待入库
    const STATUS_STORED = 2;   // 已入库
    const STATUS_SETTLED = 3;  // 已结算

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

    // 关联仓库
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    // 关联预报
    public function forecast()
    {
        return $this->belongsTo(WarehouseForecast::class, 'forecast_id');
    }

    // 关联创建人
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // 关联更新人
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // 只查询未删除的记录
    public function scopeNotDeleted($query)
    {
        return $query->where('deleted', 0);
    }

    // 查询待入库的记录
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // 查询已入库的记录
    public function scopeStored($query)
    {
        return $query->where('status', self::STATUS_STORED);
    }

    // 查询已结算的记录
    public function scopeSettled($query)
    {
        return $query->where('status', self::STATUS_SETTLED);
    }

    // 设置为已入库状态
    public function markAsStored()
    {
        $this->status = self::STATUS_STORED;
        $this->storage_time = now();
        $this->updated_by = auth()->id();
        return $this->save();
    }

    // 设置为已结算状态
    public function markAsSettled()
    {
        $this->status = self::STATUS_SETTLED;
        $this->settle_time = now();
        $this->updated_by = auth()->id();
        return $this->save();
    }

    // 软删除
    public function softDelete()
    {
        $this->deleted = 1;
        $this->updated_by = auth()->id();
        return $this->save();
    }

    // 恢复删除
    public function restore()
    {
        $this->deleted = 0;
        $this->updated_by = auth()->id();
        return $this->save();
    }

    // 获取状态列表
    public static function getStatusList()
    {
        return [
            self::STATUS_PENDING => '待入库',
            self::STATUS_STORED => '已入库',
            self::STATUS_SETTLED => '已结算'
        ];
    }

    // 检查是否可以入库
    public function canBeStored()
    {
        return $this->status === self::STATUS_PENDING && !$this->deleted;
    }

    // 检查是否可以结算
    public function canBeSettled()
    {
        return $this->status === self::STATUS_STORED && !$this->deleted;
    }

    // 检查是否已删除
    public function isDeleted()
    {
        return $this->deleted === 1;
    }

    // 获取创建人名称
    public function getCreatorNameAttribute()
    {
        return $this->creator ? $this->creator->username : '';
    }

    // 获取更新人名称
    public function getUpdaterNameAttribute()
    {
        return $this->updater ? $this->updater->username : '';
    }

    // 获取仓库名称
    public function getWarehouseNameAttribute()
    {
        return $this->warehouse ? $this->warehouse->name : '';
    }
}