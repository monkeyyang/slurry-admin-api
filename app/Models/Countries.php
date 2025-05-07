<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Countries extends Model
{
    protected $table = 'countries';

    protected $fillable = [
        'name_zh',
        'name_en',
        'code',
        'code2',
        'status',
    ];

    // 状态常量
    const STATUS_DISABLED = 0;  // 禁用
    const STATUS_ENABLED = 1;   // 启用

    // 获取状态文本
    private mixed $status;

    /**
     * 获取状态文本
     *
     * @return string
     */
    public function getStatusText(): string
    {
        return match($this->status) {
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用'
        };
    }

    /**
     * 查询可用国家列表
     *
     * @param $query
     * @return mixed
     */
    public function scopeEnabled($query): mixed
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 禁用国家
     *
     * @param int $id 国家ID
     * @return bool 操作结果
     */
    public static function disable(int $id): bool
    {
        return self::where('id', $id)->update(['status' => self::STATUS_DISABLED]);
    }

    /**
     * 启用国家
     *
     * @param int $id 国家ID
     * @return bool 操作结果
     */
    public static function enable(int $id): bool
    {
        return self::where('id', $id)->update(['status' => self::STATUS_ENABLED]);
    }

    /**
     * 获取使用该国家的仓库数量
     * 
     * @return int 仓库数量
     */
    public function getWarehouseCountAttribute()
    {
        return \DB::table('admin_warehouse')
            ->where('country', $this->code)
            ->where('deleted', 0)
            ->count();
    }
}
