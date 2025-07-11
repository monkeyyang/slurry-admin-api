<?php

namespace App\Services;

use App\Models\ItunesTradeRate;
use Illuminate\Support\Collection;

class ItunesTradeRateService
{
    /**
     * 获取带关联数据的交易汇率列表（优化版本）
     *
     * @param array $params
     * @return array
     */
    public function getTradeRatesWithRelations(array $params): array
    {
        $query = ItunesTradeRate::query()->with(['customer', 'country']);

        // 使用 JOIN 来支持筛选，避免使用 with() 预加载
        $needsUserJoin = !empty($params['uid']) || !empty($params['user_name']);
        $needsCountryJoin = !empty($params['country_code']) || !empty($params['country_name']);

        if ($needsUserJoin) {
            $query->leftJoin('admin_users as users', 'itunes_trade_rates.uid', '=', 'users.id');
        }

        if ($needsCountryJoin) {
            $query->leftJoin('countries', 'itunes_trade_rates.country_code', '=', 'countries.code');
        }

        // 选择字段，避免字段冲突
        $selectFields = ['itunes_trade_rates.*'];
        if ($needsUserJoin) {
            $selectFields[] = 'users.username as user_name';
            $selectFields[] = 'users.id as user_id';
        }
        if ($needsCountryJoin) {
            $selectFields[] = 'countries.name as country_name';
            $selectFields[] = 'countries.name_en as country_name_en';
        }
        $query->select($selectFields);

        // 应用筛选条件
        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (!empty($params['country_code'])) {
            $query->byCountry($params['country_code']);
        }

        if (!empty($params['card_type'])) {
            $query->byCardType($params['card_type']);
        }

        if (!empty($params['card_form'])) {
            $query->byCardForm($params['card_form']);
        }

        if (!empty($params['uid'])) {
            $query->byUser($params['uid']);
        }

        // 支持按用户名筛选
        if (!empty($params['user_name'])) {
            $query->where('users.username', 'like', '%' . $params['user_name'] . '%');
        }

        // 支持按国家名筛选
        if (!empty($params['country_name'])) {
            $query->where(function($q) use ($params) {
                $q->where('countries.name', 'like', '%' . $params['country_name'] . '%')
                  ->orWhere('countries.name_en', 'like', '%' . $params['country_name'] . '%');
            });
        }

        if (!empty($params['room_id'])) {
            $query->byRoom($params['room_id']);
        }

        if (!empty($params['group_id'])) {
            $query->byGroup($params['group_id']);
        }

        // 关键词搜索
        if (!empty($params['keyword'])) {
            $query->byKeyword($params['keyword']);
        }

        // 分页参数
        $pageNum = $params['pageNum'] ?? 1;
        $pageSize = min($params['pageSize'] ?? 10, 100);

        // 执行分页查询
        $result = $query->orderBy('created_at', 'desc')
                       ->paginate($pageSize, ['*'], 'page', $pageNum);

        $tradeRates = collect($result->items());

        // 批量获取跨库关联数据（room 和 group）
        $crossDbData = $this->batchLoadCrossDbData($tradeRates);

        // 转换为API格式
        $data = $tradeRates->map(function ($tradeRate) use ($crossDbData) {
            return $this->toApiArrayWithRelations($tradeRate, $crossDbData);
        })->toArray();

        return [
            'data' => $data,
            'total' => $result->total(),
            'pageNum' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ];
    }

    /**
     * 批量加载跨库关联数据（仅 room 和 group）
     *
     * @param Collection $tradeRates
     * @return array
     */
    protected function batchLoadCrossDbData(Collection $tradeRates): array
    {
        // 收集跨库数据的ID
        $roomIds = $tradeRates->pluck('room_id')->filter()->unique()->toArray();
        $groupIds = $tradeRates->pluck('group_id')->filter()->unique()->toArray();

        // 使用模型中的批量查询方法获取跨库数据
        $rooms = ItunesTradeRate::batchGetRoomInfo($roomIds);
        $groups = ItunesTradeRate::batchGetGroupInfo($groupIds);

        return [
            'rooms' => $rooms,
            'groups' => $groups,
        ];
    }

    /**
     * 批量加载关联数据（兼容旧方法）
     *
     * @param Collection $tradeRates
     * @return array
     */
    protected function batchLoadRelatedData(Collection $tradeRates): array
    {
        // 本库数据已通过 with() 预加载，这里只处理跨库数据
        return $this->batchLoadCrossDbData($tradeRates);
    }

    /**
     * 转换为API数组格式（带关联数据）
     *
     * @param ItunesTradeRate $tradeRate
     * @param array $crossDbData
     * @return array
     */
    protected function toApiArrayWithRelations(ItunesTradeRate $tradeRate, array $crossDbData): array
    {
        // 跨库数据从批量查询结果中获取
        $room = $crossDbData['rooms']->get($tradeRate->room_id);
        $group = $crossDbData['groups']->get($tradeRate->group_id);

        // 从 JOIN 查询结果中获取用户和国家信息（如果有的话）
        $user = null;
        if (isset($tradeRate->customer)) {
            $user = [
                'id' => $tradeRate->customer->id ?? $tradeRate->uid,
                'name' => $tradeRate->customer->nickname,
            ];
        }

        $country = null;
        if (isset($tradeRate->country)) {

            $country = [
                'code' => $tradeRate->country->code,
                'name' => $tradeRate->country->name_zh,
                'name_en' => $tradeRate->country->name_en ?? null,
            ];
        }

        return [
            'id' => $tradeRate->id,
            'uid' => $tradeRate->uid,
            'name' => $tradeRate->name,
            'country_code' => $tradeRate->country_code,
            'group_id' => $tradeRate->group_id,
            'room_id' => $tradeRate->room_id,
            'card_type' => $tradeRate->card_type,
            'card_type_text' => $tradeRate->card_type_text,
            'card_form' => $tradeRate->card_form,
            'card_form_text' => $tradeRate->card_form_text,
            'amount_constraint' => $tradeRate->amount_constraint,
            'amount_constraint_text' => $tradeRate->amount_constraint_text,
            'fixed_amounts' => $tradeRate->fixed_amounts,
            'multiple_base' => $tradeRate->multiple_base,
            'max_amount' => $tradeRate->max_amount,
            'min_amount' => $tradeRate->min_amount,
            'rate' => $tradeRate->rate,
            'status' => $tradeRate->status,
            'status_text' => $tradeRate->status_text,
            'description' => $tradeRate->description,
            'created_at' => $tradeRate->created_at ? $tradeRate->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $tradeRate->updated_at ? $tradeRate->updated_at->format('Y-m-d H:i:s') : null,
            // 从 JOIN 查询结果获取的关联数据
            'user' => $user,
            'country' => $country,
            // 跨库独立查询数据
            'room' => $room ? [
                'room_id' => $room->room_id,
                'room_name' => $room->name ?? null,
            ] : null,
            'group' => $group ? [
                'id' => $group->id,
                'name' => $group->name ?? null,
            ] : null,
        ];
    }

    /**
     * 获取单个交易汇率详情（带关联数据）
     *
     * @param int $id
     * @return array|null
     */
    public function getTradeRateDetail(int $id): ?array
    {
        // 不使用预加载，避免触发 User 模型错误
        $tradeRate = ItunesTradeRate::find($id);

        if (!$tradeRate) {
            return null;
        }

        // 获取跨库关联数据
        $crossDbData = $this->batchLoadCrossDbData(collect([$tradeRate]));

        return $this->toApiArrayWithRelations($tradeRate, $crossDbData);
    }

    /**
     * 创建或更新交易汇率
     *
     * @param array $data
     * @param int|null $id
     * @return ItunesTradeRate
     */
    public function createOrUpdateTradeRate(array $data, int $id = null): ItunesTradeRate
    {
        if ($id) {
            $tradeRate = ItunesTradeRate::findOrFail($id);
            $tradeRate->update($data);
        } else {
            $tradeRate = ItunesTradeRate::create($data);
        }

        return $tradeRate;
    }

    /**
     * 批量更新状态
     *
     * @param array $ids
     * @param string $status
     * @return int
     */
    public function batchUpdateStatus(array $ids, string $status): int
    {
        return ItunesTradeRate::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 根据国家代码获取汇率列表（不分页）
     *
     * @param string $countryCode
     * @return array
     */
    public function getRatesByCountry(string $countryCode): array
    {
        $query = ItunesTradeRate::query()->with(['customer', 'country']);

        // 按国家代码筛选
        $query->byCountry($countryCode);

        // 只获取启用状态的汇率
        $query->active();

        // 按创建时间倒序排列
        $tradeRates = $query->orderBy('created_at', 'desc')->get();

        // 批量获取跨库关联数据
        $crossDbData = $this->batchLoadCrossDbData($tradeRates);

        // 转换为API格式
        return $tradeRates->map(function ($tradeRate) use ($crossDbData) {
            return $this->toApiArrayWithRelations($tradeRate, $crossDbData);
        })->toArray();
    }

    /**
     * 删除汇率（检查是否被计划使用）
     *
     * @param int $id
     * @return array
     * @throws \Exception
     */
    public function deleteTradeRate(int $id): array
    {
        $tradeRate = ItunesTradeRate::find($id);

        if (!$tradeRate) {
            throw new \Exception('汇率不存在');
        }

        // 检查是否有有效的计划正在使用这个汇率
        $activePlans = \App\Models\ItunesTradePlan::where('rate_id', $id)->get();

        if ($activePlans->isNotEmpty()) {
            $planNames = $activePlans->pluck('name')->toArray();
            throw new \Exception('无法删除汇率，以下计划正在使用该汇率：' . implode('、', $planNames));
        }

        $tradeRate->delete();

        return [
            'success' => true,
            'message' => '汇率删除成功',
        ];
    }

    /**
     * 批量删除汇率（检查是否被计划使用）
     *
     * @param array $ids
     * @return array
     * @throws \Exception
     */
    public function batchDeleteTradeRates(array $ids): array
    {
        // 检查所有汇率是否存在
        $tradeRates = ItunesTradeRate::whereIn('id', $ids)->get();
        $foundIds = $tradeRates->pluck('id')->toArray();
        $missingIds = array_diff($ids, $foundIds);

        if (!empty($missingIds)) {
            throw new \Exception('以下汇率不存在：' . implode('、', $missingIds));
        }

        // 检查是否有有效的计划正在使用这些汇率
        $activePlans = \App\Models\ItunesTradePlan::whereIn('rate_id', $ids)->get();

        if ($activePlans->isNotEmpty()) {
            $usedRates = [];
            foreach ($activePlans as $plan) {
                $rateName = $tradeRates->where('id', $plan->rate_id)->first()->name ?? "ID:{$plan->rate_id}";
                $usedRates[] = "{$rateName}（被计划：{$plan->name} 使用）";
            }
            throw new \Exception('无法删除以下汇率，它们正在被计划使用：' . implode('、', $usedRates));
        }

        $deletedCount = ItunesTradeRate::whereIn('id', $ids)->delete();

        return [
            'success' => true,
            'message' => "成功删除 {$deletedCount} 个汇率",
            'deleted_count' => $deletedCount,
        ];
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $total = ItunesTradeRate::count();
        $active = ItunesTradeRate::active()->count();
        $inactive = ItunesTradeRate::inactive()->count();

        $byCardType = ItunesTradeRate::selectRaw('card_type, count(*) as count')
            ->groupBy('card_type')
            ->pluck('count', 'card_type')
            ->toArray();

        $byCountry = ItunesTradeRate::selectRaw('country_code, count(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'by_card_type' => $byCardType,
            'by_country' => $byCountry,
        ];
    }
}
