<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WechatRooms extends Model
{
    protected $table = 'wechat_rooms';

    protected $primaryKey = 'id';
    protected $guarded = [];

    const UPDATED_AT = 'created_at';

    const OPEN_STATUS = 1;
    const CLOSE_STATUS = 0;

    const ROOM_TYPE_OUT = 1; // 出卡
    const ROOM_TYPE_IN = 2; // 收卡
    const ROOM_TYPE_ADD = 3; // 代加
    const ROOM_TYPE_PAYED = 4; // 代付
    const ROOM_TYPE_OTHER = 100;

    /**
     * 创建或更新群组
     *
     * @param string $roomId
     * @param array $data
     * @return WechatRooms
     */
    public static function createOrUpdate(string $roomId, array $data): WechatRooms
    {
        $record = self::where('room_id', $roomId)->first();
        if ($record) {
            $record->update($data);
            return $record;
        } else {
            $data['room_id'] = $roomId;
            return self::create($data);
        }
    }

    /**
     * 开启群组机器人
     *
     * @return bool
     */
    public function openBot(): bool
    {
        $this->is_open = self::OPEN_STATUS;
        return $this->save();
    }

    /**
     * 关闭群组机器人
     *
     * @return mixed
     */
    public function closeBot(): mixed
    {
        $this->is_open = self::CLOSE_STATUS;
        return $this->save();
    }

    /**
     * 根据群组ID获取一条有效数据，可附加其他查询条件
     *
     * @param string $roomId 群组ID
     * @param array $conditions 额外的查询条件 ['字段名' => '值']
     * @return WechatRooms|null 返回查询结果或null
     */
    public static function getByRoomId(string $roomId, array $conditions = []): ?WechatRooms
    {
        $query = self::where('room_id', $roomId)->active();

        // 添加额外的查询条件
        foreach ($conditions as $field => $value) {
            $query->where($field, $value);
        }

        return $query->first();
    }

    /**
     * 出卡群组
     *
     * @param Builder $query
     * @return Builder
     */
    public static function scopeOutRoom(Builder $query): Builder
    {
        return $query->where('room_type', self::ROOM_TYPE_OUT);
    }

    /**
     * 收卡群组
     *
     * @param Builder $query
     * @return Builder
     */
    public static function scopeInRoom(Builder $query): Builder
    {
        return $query->where('room_type', self::ROOM_TYPE_IN);
    }

    /**
     * 代加群组
     *
     * @param Builder $query
     * @return Builder
     */
    public static function scopeAddRoom(Builder $query): Builder
    {
        return $query->where('room_type', self::ROOM_TYPE_ADD);
    }

    /**
     * 仅查询未删除的记录
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * 仅查询已开启的记录
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', self::OPEN_STATUS);
    }
}
