<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItunesTradeRate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'itunes_trade_rates';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    const CARD_TYPE_FAST = 'fast'; // 快卡
    const CARD_TYPE_SLOW = 'slow';   // 慢卡
    const CARD_FORM_IMAGE = 'image';  // 卡图
    const CARD_FORM_CODE = 'code'; // 卡密
    const STATUS_ACTIVE = 'active'; // 启用状态
    const STATUS_INACTIVE = 'inactive'; // 禁用状态
    const AMOUNT_CONSTRAINT_ALL = 'all';
    const AMOUNT_CONSTRAINT_MULTIPLE = 'multiple';
    const AMOUNT_CONSTRAINT_FIXED = 'fixed';

    protected $fillable = [
        'uid',
        'name',
        'country_code',
        'group_id',
        'room_id',
        'card_type',
        'card_form',
        'amount_constraint',
        'fixed_amounts',
        'multiple_base',
        'max_amount',
        'min_amount',
        'rate',
        'status',
        'description',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'fixed_amounts' => 'array',
        'max_amount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'multiple_base' => 'integer',
    ];

    /**
     * 获取状态文本
     * @return string
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => '激活',
            self::STATUS_INACTIVE => '禁用',
        };
    }

    public function getCardTypeTextAttribute(): string
    {
        return match ($this->card_type) {
            self::CARD_TYPE_FAST => '快卡',
            self::CARD_TYPE_SLOW => '慢卡'
        };
    }

    public function getCardFormTextAttribute(): string
    {
        return match ($this->card_form) {
            self::CARD_FORM_IMAGE => '卡图',
            self::CARD_FORM_CODE => '卡密'
        };
    }

    public function getAmountConstraintTextAttribute(): string
    {
        return match ($this->amount_constraint) {
            self::AMOUNT_CONSTRAINT_ALL => '全面额',
            self::AMOUNT_CONSTRAINT_MULTIPLE => '倍数要求',
            self::AMOUNT_CONSTRAINT_FIXED => '固定面额'
        };
    }

    // 关联客户 - 本库关联，可以使用外键
    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    // 关联国家 - 本库关联，可以使用外键
    public function country(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Countries::class, 'country_code', 'code');
    }


    /**
     * 获取群聊信息（独立查询）
     * @return MrRoom|null
     */
    public function getRoomInfo(): ?MrRoom
    {
        if (empty($this->room_id)) {
            return null;
        }

        return MrRoom::where('room_id', $this->room_id)->first();
    }

    /**
     * 获取群组信息（独立查询 - 跨库）
     * @return MrRoomGroup|null
     */
    public function getGroupInfo(): ?MrRoomGroup
    {
        if (empty($this->group_id)) {
            return null;
        }

        return MrRoomGroup::where('id', $this->group_id)->first();
    }

    /**
     * 转换为API数组格式
     * @return array
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'name' => $this->name,
            'country_code' => $this->country_code,
            'group_id' => $this->group_id,
            'room_id' => $this->room_id,
            'card_type' => $this->card_type,
            'card_type_text' => $this->card_type_text,
            'card_form' => $this->card_form,
            'card_form_text' => $this->card_form_text,
            'amount_constraint' => $this->amount_constraint,
            'amount_constraint_text' => $this->amount_constraint_text,
            'fixed_amounts' => $this->fixed_amounts,
            'multiple_base' => $this->multiple_base,
            'max_amount' => $this->max_amount,
            'min_amount' => $this->min_amount,
            'rate' => $this->rate,
            'status' => $this->status,
            'status_text' => $this->status_text,
            'description' => $this->description,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            // 跨库独立查询数据
            'room' => $this->room ? [] : null,
            'group' => $this->group ? [] : null,
        ];
    }

    /**
     * 批量获取客户信息（本库关联，保留用于兼容）
     * @param array $userIds
     * @return \Illuminate\Support\Collection
     */
    public static function batchGetCustomerInfo(array $userIds): \Illuminate\Support\Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');
    }

    /**
     * 批量获取国家信息（本库关联，保留用于兼容）
     * @param array $countryCodes
     * @return \Illuminate\Support\Collection
     */
    public static function batchGetCountryInfo(array $countryCodes): \Illuminate\Support\Collection
    {
        if (empty($countryCodes)) {
            return collect();
        }

        return Countries::whereIn('code', $countryCodes)->get()->keyBy('code');
    }

    /**
     * 批量获取房间信息
     * @param array $roomIds
     * @return \Illuminate\Support\Collection
     */
    public static function batchGetRoomInfo(array $roomIds): \Illuminate\Support\Collection
    {
        if (empty($roomIds)) {
            return collect();
        }

        return MrRoom::whereIn('room_id', $roomIds)->get()->keyBy('room_id');
    }

    /**
     * 批量获取群组信息
     * @param array $groupIds
     * @return \Illuminate\Support\Collection
     */
    public static function batchGetGroupInfo(array $groupIds): \Illuminate\Support\Collection
    {
        if (empty($groupIds)) {
            return collect();
        }

        return MrRoomGroup::whereIn('id', $groupIds)->get()->keyBy('id');
    }

    /**
     * 作用域：按状态筛选
     * @param $query
     * @param string $status
     * @return mixed
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：按国家代码筛选
     * @param $query
     * @param string $countryCode
     * @return mixed
     */
    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * 作用域：按卡类型筛选
     * @param $query
     * @param string $cardType
     * @return mixed
     */
    public function scopeByCardType($query, string $cardType)
    {
        return $query->where('card_type', $cardType);
    }

    /**
     * 作用域：按卡形式筛选
     * @param $query
     * @param string $cardForm
     * @return mixed
     */
    public function scopeByCardForm($query, string $cardForm)
    {
        return $query->where('card_form', $cardForm);
    }

    /**
     * 作用域：按用户ID筛选
     * @param $query
     * @param int $uid
     * @return mixed
     */
    public function scopeByUser($query, int $uid)
    {
        return $query->where('uid', $uid);
    }

    /**
     * 作用域：按房间ID筛选
     * @param $query
     * @param string $roomId
     * @return mixed
     */
    public function scopeByRoom($query, string $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * 作用域：按群组ID筛选
     * @param $query
     * @param int $groupId
     * @return mixed
     */
    public function scopeByGroup($query, int $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * 作用域：启用状态
     * @param $query
     * @return mixed
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 作用域：禁用状态
     * @param $query
     * @return mixed
     */
    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    /**
     * 作用域：关键词搜索
     * @param $query
     * @param string $keyword
     * @return mixed
     */
    public function scopeByKeyword($query, string $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
              ->orWhere('description', 'like', "%{$keyword}%");
        });
    }
}
