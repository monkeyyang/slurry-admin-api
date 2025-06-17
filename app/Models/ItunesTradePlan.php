<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItunesTradePlan extends Model
{
    use HasFactory;

    protected $table = 'itunes_trade_plans';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    const STATUS_ENABLED = 'enabled';
    const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'uid',
        'name',
        'country_code',
        'rate_id',
        'plan_days',
        'float_amount',
        'total_amount',
        'exchange_interval',
        'day_interval',
        'daily_amounts',
        'completed_days',
        'bind_room',
        'status',
        'description',
    ];

    protected $casts = [
        'daily_amounts' => 'array',
        'completed_days' => 'array',
        'float_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'plan_days' => 'integer',
        'exchange_interval' => 'integer',
        'day_interval' => 'integer',
        'rate_id' => 'integer',
        'uid' => 'integer',
        'bind_room' => 'integer'
    ];

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ENABLED => '启用',
            self::STATUS_DISABLED => '禁用',
            default => '未知',
        };
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo('App\Models\User', 'uid');
    }

    /**
     * 关联汇率
     */
    public function rate()
    {
        return $this->belongsTo(ItunesTradeRate::class, 'rate_id');
    }

    /**
     * 关联国家
     */
    public function country()
    {
        return $this->belongsTo(Countries::class, 'country_code', 'code');
    }

    /**
     * 获取用户信息（安全获取）
     */
    public function getUserInfo()
    {
        if ($this->relationLoaded('user')) {
            return $this->user;
        }

        if (empty($this->uid)) {
            return null;
        }

        return \App\Models\User::where('id', $this->uid)->first();
    }

    /**
     * 获取汇率信息（安全获取）
     */
    public function getRateInfo()
    {
        if ($this->relationLoaded('rate')) {
            return $this->rate;
        }

        if (empty($this->rate_id)) {
            return null;
        }

        return ItunesTradeRate::where('id', $this->rate_id)->first();
    }

    /**
     * 获取国家信息（安全获取）
     */
    public function getCountryInfo()
    {
        if ($this->relationLoaded('country')) {
            return $this->country;
        }

        if (empty($this->country_code)) {
            return null;
        }

        return Countries::where('code', $this->country_code)->first();
    }

    /**
     * 转换为API数组格式
     */
    public function toApiArray(): array
    {
        $userInfo = $this->getUserInfo();
        $rateInfo = $this->getRateInfo();
        $countryInfo = $this->getCountryInfo();

        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'name' => $this->name,
            'country_code' => $this->country_code,
            'country_name' => $countryInfo ? $countryInfo->name_zh : null,
            'rate_id' => $this->rate_id,
            'rate_name' => $rateInfo ? $rateInfo->name : null,
            'plan_days' => $this->plan_days,
            'float_amount' => (string) $this->float_amount,
            'total_amount' => (string) $this->total_amount,
            'exchange_interval' => $this->exchange_interval,
            'day_interval' => $this->day_interval,
            'daily_amounts' => json_encode($this->daily_amounts),
            'completed_days' => json_encode($this->completed_days),
            'bind_room' => $this->bind_room,
            'status' => $this->status,
            'status_text' => $this->status_text,
            'description' => $this->description,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            'user' => $userInfo ? [
                'id' => $userInfo->id,
                'username' => $userInfo->username ?? null,
            ] : null,
            'rate' => $rateInfo ? [
                'id' => $rateInfo->id,
                'name' => $rateInfo->name,
                'rate' => $rateInfo->rate,
            ] : null,
        ];
    }

    /**
     * 作用域：按状态筛选
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：按国家代码筛选
     */
    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * 作用域：按汇率ID筛选
     */
    public function scopeByRate($query, int $rateId)
    {
        return $query->where('rate_id', $rateId);
    }

    /**
     * 作用域：按用户ID筛选
     */
    public function scopeByUser($query, int $uid)
    {
        return $query->where('uid', $uid);
    }

    /**
     * 作用域：按绑定群聊筛选
     */
    public function scopeByBindRoom($query, int $bindRoom)
    {
        return $query->where('bind_room', $bindRoom);
    }

    /**
     * 作用域：启用状态
     */
    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 作用域：禁用状态
     */
    public function scopeDisabled($query)
    {
        return $query->where('status', self::STATUS_DISABLED);
    }

    /**
     * 作用域：关键词搜索
     */
    public function scopeByKeyword($query, string $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
              ->orWhere('description', 'like', "%{$keyword}%");
        });
    }
}
