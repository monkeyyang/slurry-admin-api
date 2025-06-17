<?php

namespace App\Services;

use App\Models\ItunesTradePlan;
use Illuminate\Support\Collection;

class ItunesTradePlanService
{
    /**
     * 获取计划列表（分页）
     *
     * @param array $params
     * @return array
     */
    public function getPlansWithPagination(array $params): array
    {
        $query = ItunesTradePlan::query();

        // 应用筛选条件
        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (!empty($params['country_code'])) {
            $query->byCountry($params['country_code']);
        }

        if (!empty($params['rate_id'])) {
            $query->byRate($params['rate_id']);
        }

        if (!empty($params['uid'])) {
            $query->byUser($params['uid']);
        }

        if (isset($params['bind_room'])) {
            $query->byBindRoom($params['bind_room']);
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

        $plans = collect($result->items());

        // 转换为API格式
        $data = $plans->map(function ($plan) {
            return $plan->toApiArray();
        })->toArray();

        return [
            'list' => $data,
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
            'totalPages' => ceil($result->total() / $result->perPage()),
        ];
    }

    /**
     * 获取单个计划详情
     *
     * @param int $id
     * @return array|null
     */
    public function getPlanDetail(int $id): ?array
    {
        $plan = ItunesTradePlan::find($id);

        if (!$plan) {
            return null;
        }

        return $plan->toApiArray();
    }

    /**
     * 创建或更新计划
     *
     * @param array $data
     * @param int|null $id
     * @return ItunesTradePlan
     */
    public function createOrUpdatePlan(array $data, int $id = null): ItunesTradePlan
    {
        // 确保 JSON 字段正确处理
        if (isset($data['daily_amounts']) && is_array($data['daily_amounts'])) {
            $data['daily_amounts'] = $data['daily_amounts'];
        }

        if (isset($data['completed_days']) && is_array($data['completed_days'])) {
            $data['completed_days'] = $data['completed_days'];
        }

        if ($id) {
            $plan = ItunesTradePlan::findOrFail($id);
            $plan->update($data);
        } else {
            // 创建时设置用户ID
            $data['uid'] = auth()->id() ?? 1;
            $plan = ItunesTradePlan::create($data);
        }

        return $plan;
    }

    /**
     * 删除计划
     *
     * @param int $id
     * @return bool
     */
    public function deletePlan(int $id): bool
    {
        $plan = ItunesTradePlan::find($id);
        
        if (!$plan) {
            return false;
        }

        return $plan->delete();
    }

    /**
     * 批量删除计划
     *
     * @param array $ids
     * @return int
     */
    public function batchDeletePlans(array $ids): int
    {
        return ItunesTradePlan::whereIn('id', $ids)->delete();
    }

    /**
     * 更新计划状态
     *
     * @param int $id
     * @param string $status
     * @return ItunesTradePlan|null
     */
    public function updatePlanStatus(int $id, string $status): ?ItunesTradePlan
    {
        $plan = ItunesTradePlan::find($id);
        
        if (!$plan) {
            return null;
        }

        $plan->update(['status' => $status]);
        
        return $plan;
    }

    /**
     * 添加天数计划
     *
     * @param int $id
     * @param int $additionalDays
     * @return ItunesTradePlan|null
     */
    public function addDaysToPlan(int $id, int $additionalDays): ?ItunesTradePlan
    {
        $plan = ItunesTradePlan::find($id);
        
        if (!$plan) {
            return null;
        }

        // 更新计划天数
        $newPlanDays = $plan->plan_days + $additionalDays;
        
        // 扩展每日金额数组
        $dailyAmounts = $plan->daily_amounts;
        $avgAmount = $plan->total_amount / $newPlanDays;
        
        // 为新增的天数添加平均金额
        for ($i = 0; $i < $additionalDays; $i++) {
            $dailyAmounts[] = $avgAmount;
        }

        $plan->update([
            'plan_days' => $newPlanDays,
            'daily_amounts' => $dailyAmounts,
        ]);

        return $plan;
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $total = ItunesTradePlan::count();
        $enabled = ItunesTradePlan::enabled()->count();
        $disabled = ItunesTradePlan::disabled()->count();

        $byCountry = ItunesTradePlan::selectRaw('country_code, count(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code')
            ->toArray();

        $byStatus = ItunesTradePlan::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'by_country' => $byCountry,
            'by_status' => $byStatus,
        ];
    }
} 