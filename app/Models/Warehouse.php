<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $table = 'admin_warehouse';
    
    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    protected $fillable = [
        'name',
        'status',
        'remark',
        'create_time',
        'update_time',
        'deleted'
    ];

    protected $casts = [
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'status' => 'integer',
        'deleted' => 'integer'
    ];

    // 状态常量
    const STATUS_DISABLED = 0;  // 禁用
    const STATUS_ENABLED = 1;   // 启用

    // 获取状态文本
    public function getStatusTextAttribute()
    {
        return match($this->status) {
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
            default => '未知状态',
        };
    }

    // 关联库存
    public function inventories()
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    // 关联预报
    public function forecasts()
    {
        return $this->hasMany(WarehouseForecast::class);
    }

    // 关联管理员
    public function managers()
    {
        return $this->belongsToMany(User::class, 'admin_warehouse_user', 'warehouse_id', 'user_id');
    }

    // 只查询未删除的记录
    public function scopeNotDeleted($query)
    {
        return $query->where('deleted', 0);
    }

    // 只查询启用的记录
    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    // 获取可用的仓库列表（未删除且已启用）
    public function scopeAvailable($query)
    {
        return $query->notDeleted()->enabled();
    }

    // 软删除
    public function softDelete()
    {
        $this->deleted = 1;
        return $this->save();
    }

    // 恢复删除
    public function restore()
    {
        $this->deleted = 0;
        return $this->save();
    }

    // 启用
    public function enable()
    {
        $this->status = self::STATUS_ENABLED;
        return $this->save();
    }

    // 禁用
    public function disable()
    {
        $this->status = self::STATUS_DISABLED;
        return $this->save();
    }

    // 检查是否已删除
    public function isDeleted()
    {
        return $this->deleted === 1;
    }

    // 检查是否启用
    public function isEnabled()
    {
        return $this->status === self::STATUS_ENABLED;
    }

    // 获取状态列表
    public static function getStatusList()
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用'
        ];
    }

    // 检查是否有指定管理员
    public function hasManager($userId)
    {
        return $this->managers()->where('user_id', $userId)->exists();
    }

    // 添加管理员
    public function addManager($userId)
    {
        if (!$this->hasManager($userId)) {
            return $this->managers()->attach($userId);
        }
        return false;
    }

    // 移除管理员
    public function removeManager($userId)
    {
        return $this->managers()->detach($userId);
    }

    // 获取管理员ID列表
    public function getManagerIds()
    {
        return $this->managers()->pluck('user_id')->toArray();
    }
}