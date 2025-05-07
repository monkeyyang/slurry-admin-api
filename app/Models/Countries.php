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

    // 启用
    public function enable(): bool
    {
        $this->status = self::STATUS_ENABLED;
        return $this->save();
    }

    // 禁用
    public function disable(): bool
    {
        $this->status = self::STATUS_DISABLED;
        return $this->save();
    }
}
