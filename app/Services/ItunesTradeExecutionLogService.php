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
        $pageSize = min($params['pageSize'] ?? 20, 5000);

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
            'after_amount' => (float) $log->after_amount,
            'status' => $log->status,
            'status_text' => $log->status_text,
            'exchange_time' => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : null,
            'error_message' => $log->error_message,
            'code' => $log->code,
            'room_id' => $log->room_id,
            'room_name' => $roomName,
            'wxid' => $log->wxid,
            'msgid' => $log->msgid,
            'batch_id' => $log->batch_id ?? null,
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

    /**
     * 导出执行记录到CSV
     *
     * @param array $params
     * @return void
     */
    public function exportExecutionLogs(array $params): void
    {
        // 设置PHP配置以处理大量数据
        ini_set('memory_limit', '1024M');
        set_time_limit(300); // 5分钟

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
            $query->whereHas('account', function ($accountQuery) use ($params) {
                $accountQuery->where('account', 'like', '%' . $params['account_name'] . '%');
            });
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

        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', '%' . $keyword . '%')
                  ->orWhereHas('account', function ($accountQuery) use ($keyword) {
                      $accountQuery->where('account', 'like', '%' . $keyword . '%');
                  });
            });
        }

        // 输出CSV头部
        $output = fopen('php://output', 'w');
        
        // 添加UTF-8 BOM以确保Excel正确显示中文
        fwrite($output, "\xEF\xBB\xBF");
        
        // 写入标题行
        fputcsv($output, [
            'ID',
            '兑换码',
            '国家',
            '金额',
            '账号余款',
            '账号',
            '错误信息',
            '执行状态',
            '兑换时间',
            '群聊名称',
            '计划名称',
            '汇率',
            '天数',
            '微信ID',
            '消息ID',
            '批次ID',
            '创建时间',
            '更新时间',
        ]);

        // 按时间排序并分批处理数据，避免内存溢出
        $query->orderBy('created_at', 'desc')
              ->chunk(1000, function ($logs) use ($output) {
                  foreach ($logs as $log) {
                      $this->writeCsvRow($output, $log);
                  }
              });

        fclose($output);
    }

    /**
     * 写入CSV行数据
     *
     * @param resource $output
     * @param ItunesTradeAccountLog $log
     * @return void
     */
    private function writeCsvRow($output, ItunesTradeAccountLog $log): void
    {
        // 获取账号信息
        $account = $log->account;
        $accountName = $account ? $account->account : '未知账号';
        
        // 获取计划信息
        $plan = $log->plan;
        $planName = $plan ? $plan->name : '未知计划';
        
        // 获取汇率信息
        $rate = $log->rate;
        $rateValue = $rate ? $rate->rate : '0';
        
        // 获取群聊信息
        $roomName = '';
        if ($log->room_id) {
            try {
                $roomInfo = \App\Models\MrRoom::where('room_id', $log->room_id)->first();
                if ($roomInfo) {
                    $roomName = $roomInfo->room_name;
                }
            } catch (\Exception $e) {
                // 群聊信息获取失败时跳过
                $roomName = '获取失败';
            }
        }
        
        // 获取国家信息
        $countryName = $log->country_code;
        try {
            $country = \App\Models\Countries::where('code', $log->country_code)->first();
            if ($country) {
                $countryName = $country->name;
            }
        } catch (\Exception $e) {
            // 国家信息获取失败时使用代码
        }
        
        // 状态文本转换
        $statusText = match ($log->status) {
            'success' => '成功',
            'failed' => '失败',
            'pending' => '处理中',
            default => $log->status,
        };

        $row = [
            $log->id,
            $log->code ?: '',
            $countryName,
            $log->amount ?: 0,
            $log->after_amount ?: 0,
            $accountName,
            $log->error_message ?: '',
            $statusText,
            $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : '',
            $roomName,
            $planName,
            $rateValue,
            $log->day ?: '',
            $log->wxid ?: '',
            $log->msgid ?: '',
            $log->batch_id ?: '',
            $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '',
            $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : '',
        ];
        
        fputcsv($output, $row);
    }
}
