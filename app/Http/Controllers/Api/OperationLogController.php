<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\OperationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationLogController extends Controller
{
    public function getVerifyCode(Request $request)
    {
        $validated = $request->validate([
            'room_id'    => 'required|string|max:255',
            'msgid'      => 'nullable|string|max:255',
            'wxid'       => 'nullable|string|max:255',
            'accounts'   => 'required|array|min:1|max:100', // 限制最多100个账号
            'accounts.*' => 'required|string|min:10|max:200', // 账号长度限制
        ], [
            'room_id.required'   => '群聊ID不能为空',
            'wxid.required'      => '微信ID不能为空',
            'accounts.required'     => '账号不能为空',
            'accounts.min'          => '至少需要一个账号',
            'accounts.max'          => '最多支持100账号',
            'accounts.*.required'   => '账号不能为空',
            'accounts.*.min'        => '账号长度至少10位',
            'accounts.*.max'        => '账号长度最多200位',
        ]);

        try {
            // 记录查码请求到操作日志
            foreach ($validated['accounts'] as $account) {
                OperationLog::create([
                    'uid' => auth()->id(),
                    'room_id' => $validated['room_id'],
                    'wxid' => $validated['wxid'],
                    'operation_type' => 'getVerifyCode',
                    'target_account' => $account,
                    'result' => 'success',
                    'details' => '发起查码请求，消息ID：' . ($validated['msgid'] ?? '无'),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]);
            }

            // 异步分发查码任务到队列
            \App\Jobs\ProcessVerifyCodeJob::dispatch(
                $validated['room_id'],
                $validated['msgid'],
                $validated['wxid'],
                $validated['accounts'],
                auth()->id()
            );

            return response()->json([
                'code' => 0,
                'message' => '查码请求已提交，正在后台处理',
                'data' => [
                    'room_id' => $validated['room_id'],
                    'msg_id' => $validated['msgid'],
                    'accounts_count' => count($validated['accounts']),
                    'accounts' => $validated['accounts'],
                    'status' => 'processing'
                ]
            ]);

        } catch (\Exception $e) {
            // 记录错误日志
            foreach ($validated['accounts'] as $account) {
                OperationLog::create([
                    'uid' => auth()->id(),
                    'room_id' => $validated['room_id'],
                    'wxid' => $validated['wxid'],
                    'operation_type' => 'getVerifyCode',
                    'target_account' => $account,
                    'result' => 'failed',
                    'details' => '查码请求失败：' . $e->getMessage(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]);
            }

            return response()->json([
                'code' => 500,
                'message' => '查码请求处理失败',
                'data' => [
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * 创建操作记录
     */
    public function store(Request $request)
    {
        $data = $request->only([
            'operation_type',
            'target_account',
            'result',
            'details',
            'user_agent',
            'ip_address',
            'room_id',
            'wxid'
        ]);

        // 如果没有传递uid，尝试从认证用户获取
        $data['uid'] = $request->input('uid') ?? auth()->id();

        // 如果没有传递IP地址，从请求中获取
        $data['ip_address'] = $request->ip();

        // 如果没有传递用户代理，从请求中获取
        if (!$data['user_agent']) {
            $data['user_agent'] = $request->header('User-Agent');
        }

        $operationLog = OperationLog::create($data);

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => $operationLog->load('user')
        ]);
    }

    /**
     * 获取操作记录列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = OperationLog::with('user');
        // 筛选条件
        if ($request->operation_type) {
            $query->where('operation_type', $request->operation_type);
        }
        if ($request->target_account) {
            $query->where('target_account', 'like', '%' . $request->target_account . '%');
        }
        if ($request->result) {
            $query->where('result', $request->result);
        }
        if ($request->uid) {
            $query->where('uid', $request->uid);
        }
        if ($request->room_id) {
            $query->where('room_id', $request->room_id);
        }
        if ($request->wxid) {
            $query->where('wxid', $request->wxid);
        }
        if ($request->startTime) {
            $query->where('created_at', '>=', $request->startTime);
        }
        if ($request->endTime) {
            $query->where('created_at', '<=', $request->endTime);
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        // 分页
        $pageSize = $request->input('pageSize', 10);
        $pageNum  = $request->input('pageNum', 1);

        $total = $query->count();
        $data  = $query->forPage($pageNum, $pageSize)->get();

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => [
                'data'     => $data,
                'total'    => $total,
                'pageNum'  => (int)$pageNum,
                'pageSize' => (int)$pageSize,
            ]
        ]);
    }

    /**
     * 获取操作记录详情
     */
    public function show($id)
    {
        $operationLog = OperationLog::with('user')->findOrFail($id);

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => $operationLog
        ]);
    }

    /**
     * 删除操作记录
     */
    public function destroy($id)
    {
        OperationLog::destroy($id);

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => null
        ]);
    }

    /**
     * 批量删除操作记录
     */
    public function batchDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        OperationLog::whereIn('id', $ids)->delete();

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => null
        ]);
    }

    /**
     * 获取操作统计
     */
    public function statistics(Request $request)
    {
        $query = OperationLog::query();

        // 时间范围筛选
        if ($request->startTime) {
            $query->where('created_at', '>=', $request->startTime);
        }
        if ($request->endTime) {
            $query->where('created_at', '<=', $request->endTime);
        }

        // 总操作数
        $totalOperations = $query->count();

        // 成功操作数
        $successOperations = (clone $query)->where('result', 'success')->count();

        // 失败操作数
        $failedOperations = (clone $query)->where('result', '!=', 'success')->count();

        // 按操作类型统计
        $operationsByType = (clone $query)
            ->select('operation_type', DB::raw('count(*) as count'))
            ->groupBy('operation_type')
            ->pluck('count', 'operation_type')
            ->toArray();

        return response()->json([
            'code'    => 0,
            'message' => 'success',
            'data'    => [
                'totalOperations'   => $totalOperations,
                'successOperations' => $successOperations,
                'failedOperations'  => $failedOperations,
                'operationsByType'  => $operationsByType,
            ]
        ]);
    }
}
