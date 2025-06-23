<?php

namespace App\Services;

use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Collection;

class ItunesTradeExecutionLogService
{
    /**
     * 获取执行记录列表（分页）
     *
     * @param array $params
     * @return array
     */
    public function getExecutionLogsWithPagination(array $params): array
    {
        $query = ItunesTradeAccountLog::query()->with(['account', 'plan', 'rate']);

        // 应用筛选条件
        if (!empty($params['account_id'])) {
            $query->byAccount($params['account_id']);
        }

        if (!empty($params['plan_id'])) {
            $query->byPlan($params['plan_id']);
        }

        if (!empty($params['rate_id'])) {
            $query->where('rate_id', $params['rate_id']);
        }

        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (!empty($params['country_code'])) {
            $query->where('country_code', $params['country_code']);
        }

        if (!empty($params['account_name'])) {
            $query->where('account', 'like', "%{$params['account_name']}%");
        }

        if (!empty($params['day'])) {
            $query->where('day', $params['day']);
        }

        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $query->whereBetween('exchange_time', [$params['start_time'], $params['end_time']]);
        }

        if (!empty($params['room_id'])) {
            $query->where('room_id', $params['room_id']);
        }

        if(!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                // 搜索礼品卡码
                $q->where('code', 'like', '%' . $keyword . '%')
                  // 通过关联账号表搜索账号名称
                  ->orWhereHas('account', function ($accountQuery) use ($keyword) {
                      $accountQuery->where('account', 'like', '%' . $keyword . '%');
                  });
            });
        }

        // 分页参数
        $pageNum = $params['pageNum'] ?? 1;
        $pageSize = min($params['pageSize'] ?? 20, 100);

        // 执行分页查询
        $result = $query->orderBy('created_at', 'desc')
                       ->paginate($pageSize, ['*'], 'page', $pageNum);

        $logs = collect($result->items());

        // 转换为API格式
        $data = $logs->map(function ($log) {
            return $this->toApiArray($log);
        })->toArray();

        return [
            'data' => $data,
            'total' => $result->total(),
            'pageNum' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ];
    }

    /**
     * 获取单个执行记录详情
     *
     * @param int $id
     * @return array|null
     */
    public function getExecutionLogDetail(int $id): ?array
    {
        $log = ItunesTradeAccountLog::with(['account', 'plan', 'rate'])->find($id);

        if (!$log) {
            return null;
        }

        return $this->toApiArray($log);
    }

    /**
     * 转换为API数组格式
     *
     * @param ItunesTradeAccountLog $log
     * @return array
     */
    protected function toApiArray(ItunesTradeAccountLog $log): array
    {
        // 获取账号名称 - 从关联账号获取
        $accountName = '';
        if ($log->account) {
            $accountName = $log->account->account ?? '';
        }

        $roomName = '';
        if ($log->room_id) {
            $roomInfo = $log->getRoomInfo();
            if ($roomInfo) {
                $roomName = $roomInfo->room_name;
            }
        }


        return [
            'id' => $log->id,
            'account_id' => $log->account_id,
            'account' => $accountName,
            'plan_id' => $log->plan_id,
            'rate_id' => $log->rate_id,
            'country_code' => $log->country_code,
            'day' => $log->day,
            'amount' => (float) $log->amount,
            'status' => $log->status,
            'status_text' => $log->status_text,
            'exchange_time' => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : null,
            'error_message' => $log->error_message,
            'code' => $log->code,
            'room_id' => $log->room_id,
            'room_name' => $roomName,
            'wxid' => $log->wxid,
            'msgid' => $log->msgid,
            'created_at' => $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : null,
            // 关联数据
            'account_info' => $log->account ? [
                'id' => $log->account->id,
                'account' => $log->account->account,
                'country_code' => $log->account->country_code,
                'status' => $log->account->status,
            ] : null,
            'plan_info' => $log->plan,
            'rate_info' => $log->rate,
        ];
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $total = ItunesTradeAccountLog::count();
        $success = ItunesTradeAccountLog::success()->count();
        $failed = ItunesTradeAccountLog::failed()->count();
        $pending = ItunesTradeAccountLog::pending()->count();

        $byStatus = ItunesTradeAccountLog::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byCountry = ItunesTradeAccountLog::selectRaw('country_code, count(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code')
            ->toArray();

        $recentLogs = ItunesTradeAccountLog::orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return $this->toApiArray($log);
            });

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'pending' => $pending,
            'by_status' => $byStatus,
            'by_country' => $byCountry,
            'recent_logs' => $recentLogs,
        ];
    }

    /**
     * 按账号获取执行记录
     *
     * @param int $accountId
     * @param int $limit
     * @return Collection
     */
    public function getLogsByAccount(int $accountId, int $limit = 50): Collection
    {
        return ItunesTradeAccountLog::byAccount($accountId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return $this->toApiArray($log);
            });
    }

    /**
     * 按计划获取执行记录
     *
     * @param int $planId
     * @param int $limit
     * @return Collection
     */
    public function getLogsByPlan(int $planId, int $limit = 50): Collection
    {
        return ItunesTradeAccountLog::byPlan($planId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return $this->toApiArray($log);
            });
    }

    /**
     * 获取今日统计
     *
     * @return array
     */
    public function getTodayStatistics(): array
    {
        $today = now()->startOfDay();
        $tomorrow = now()->startOfDay()->addDay();

        $todayTotal = ItunesTradeAccountLog::whereBetween('created_at', [$today, $tomorrow])->count();
        $todaySuccess = ItunesTradeAccountLog::whereBetween('created_at', [$today, $tomorrow])
            ->success()
            ->count();
        $todayFailed = ItunesTradeAccountLog::whereBetween('created_at', [$today, $tomorrow])
            ->failed()
            ->count();

        $todayAmount = ItunesTradeAccountLog::whereBetween('created_at', [$today, $tomorrow])
            ->success()
            ->sum('amount');

        return [
            'today_total' => $todayTotal,
            'today_success' => $todaySuccess,
            'today_failed' => $todayFailed,
            'today_amount' => (float) $todayAmount,
            'success_rate' => $todayTotal > 0 ? round(($todaySuccess / $todayTotal) * 100, 2) : 0,
        ];
    }

    /**
     * 删除执行记录
     *
     * @param int $id
     * @return bool
     */
    public function deleteExecutionLog(int $id): bool
    {
        $log = ItunesTradeAccountLog::find($id);

        if (!$log) {
            return false;
        }

        return $log->delete();
    }

    /**
     * 批量删除执行记录
     *
     * @param array $ids
     * @return int
     */
    public function batchDeleteExecutionLogs(array $ids): int
    {
        return ItunesTradeAccountLog::whereIn('id', $ids)->delete();
    }
}
