<?php

namespace App\Http\Controllers;

use App\Services\WechatRoomBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WechatRoomBindingController extends Controller
{
    protected $wechatRoomBindingService;

    public function __construct(WechatRoomBindingService $wechatRoomBindingService)
    {
        $this->wechatRoomBindingService = $wechatRoomBindingService;
    }

    /**
     * 获取微信群组绑定状态
     */
    public function getBindingStatus()
    {
        try {
            $data = $this->wechatRoomBindingService->getBindingStatus();

            return $this->jsonOk($data);
        } catch (\Exception $e) {
            Log::error('Failed to get wechat room binding status: ' . $e->getMessage());
            return $this->jsonError('获取微信群组绑定状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新微信群组绑定状态
     */
    public function updateBindingStatus(Request $request)
    {
        try {
            $data = $request->validate([
                'enabled' => 'required|boolean',
                'autoAssign' => 'sometimes|boolean',
                'defaultRoomId' => 'sometimes|nullable|string',
                'maxPlansPerRoom' => 'sometimes|integer|min:1',
            ]);

            $settings = $this->wechatRoomBindingService->updateBindingStatus($data);

            return $this->jsonOk($settings->toApiArray());
        } catch (\Exception $e) {
            Log::error('Failed to update wechat room binding status: ' . $e->getMessage());
            return $this->jsonError('更新微信群组绑定状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取微信群组列表
     */
    public function getWechatRooms(Request $request)
    {
        try {
            $params = $request->only(['page', 'pageSize', 'keyword']);
            $data = $this->wechatRoomBindingService->getWechatRooms($params);

            return $this->jsonOk($data);
        } catch (\Exception $e) {
            Log::error('Failed to get wechat rooms: ' . $e->getMessage());
            return $this->jsonError('获取微信群组列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 绑定计划到微信群组
     */
    public function bindPlanToRoom(Request $request, $planId)
    {
        try {
            $data = $request->validate([
                'roomId' => 'required|string',
            ]);

            $binding = $this->wechatRoomBindingService->bindPlanToRoom($planId, $data['roomId']);

            return $this->jsonOk($binding->toApiArray());
        } catch (\Exception $e) {
            Log::error('Failed to bind plan to wechat room: ' . $e->getMessage());
            return $this->jsonError('绑定计划到微信群组失败: ' . $e->getMessage());
        }
    }

    /**
     * 解绑计划的微信群组
     */
    public function unbindPlanFromRoom($planId)
    {
        try {
            $result = $this->wechatRoomBindingService->unbindPlanFromRoom($planId);

            return $this->jsonOk(['success' => $result]);
        } catch (\Exception $e) {
            Log::error('Failed to unbind plan from wechat room: ' . $e->getMessage());
            return $this->jsonError('解绑计划的微信群组失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量绑定计划到微信群组
     */
    public function batchBindPlansToRoom(Request $request)
    {
        try {
            $data = $request->validate([
                'roomId' => 'required|string',
                'planIds' => 'required|array',
                'planIds.*' => 'required|string',
            ]);

            $result = $this->wechatRoomBindingService->batchBindPlansToRoom(
                $data['roomId'],
                $data['planIds']
            );

            return $this->jsonOk($result);
        } catch (\Exception $e) {
            Log::error('Failed to batch bind plans to wechat room: ' . $e->getMessage());
            return $this->jsonError('批量绑定计划到微信群组失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量解绑计划的微信群组
     */
    public function batchUnbindPlansFromRoom(Request $request)
    {
        try {
            $data = $request->validate([
                'planIds' => 'required|array',
                'planIds.*' => 'required|string',
            ]);

            $result = $this->wechatRoomBindingService->batchUnbindPlansFromRoom($data['planIds']);

            return $this->jsonOk($result);
        } catch (\Exception $e) {
            Log::error('Failed to batch unbind plans from wechat room: ' . $e->getMessage());
            return $this->jsonError('批量解绑计划的微信群组失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取微信群组执行统计
     */
    public function getWechatRoomStats(Request $request)
    {
        try {
            $roomId = $request->query('roomId');
            $data = $this->wechatRoomBindingService->getWechatRoomStats($roomId);

            return $this->jsonOk($data);
        } catch (\Exception $e) {
            Log::error('Failed to get wechat room stats: ' . $e->getMessage());
            return $this->jsonError('获取微信群组执行统计失败: ' . $e->getMessage());
        }
    }
}
