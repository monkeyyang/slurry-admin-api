<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CardQueryRule;
use App\Services\CardQueryService;

class GiftCardController extends Controller
{
    /**
     * 卡密查询服务
     *
     * @var CardQueryService
     */
    protected $cardQueryService;

    /**
     * 构造函数
     */
    public function __construct(CardQueryService $cardQueryService)
    {
        $this->cardQueryService = $cardQueryService;
    }

    /**
     * 设置卡密查询规则
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setQueryRule(Request $request)
    {
        // 检查是否是从命令行/计划任务调用
        $isConsoleRequest = app()->runningInConsole();
        
        // 只有当不是从控制台调用时才检查认证
        if (!$isConsoleRequest && !auth()->check()) {
            return response()->json([
                'code' => 401,
                'message' => 'Unauthorized.',
                'data' => null
            ], 401);
        }

        $request->validate([
            'first_interval' => 'required|integer|min:1',
            'second_interval' => 'required|integer|min:1',
            'remark' => 'nullable|string',
        ]);
        
        try {
            // 将所有现有规则设为非活跃
            CardQueryRule::where('is_active', true)->update(['is_active' => false]);
            
            // 创建新规则
            $rule = CardQueryRule::create([
                'first_interval' => $request->first_interval,
                'second_interval' => $request->second_interval,
                'remark' => $request->remark,
                'is_active' => true,
            ]);
            
            return response()->json([
                'code' => 0,
                'message' => '设置卡密查询规则成功',
                'data' => $rule
            ]);
        } catch (\Exception $e) {
            Log::error('设置卡密查询规则失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '设置卡密查询规则失败: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 批量查询卡密
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchQuery(Request $request)
    {
        // 检查是否是从命令行/计划任务调用
        $isConsoleRequest = app()->runningInConsole();
        
        // 只有当不是从控制台调用时才检查认证
        if (!$isConsoleRequest && !auth()->check()) {
            return response()->json([
                'code' => 401,
                'message' => 'Unauthorized.',
                'data' => null
            ], 401);
        }
        
        // 使用服务类处理查询逻辑
        $result = $this->cardQueryService->batchQueryCards();
        
        // 根据结果返回响应
        return response()->json($result, $result['code'] >= 400 ? $result['code'] : 200);
    }
} 