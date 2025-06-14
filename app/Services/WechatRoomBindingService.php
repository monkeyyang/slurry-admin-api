<?php

namespace App\Services;

use App\Models\ChargePlan;
use App\Models\ChargePlanWechatRoomBinding;
use App\Models\MrRoom;
use App\Models\WechatRoomBindingSetting;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WechatRoomBindingService
{
    /**
     * 获取微信群组绑定状态
     *
     * @return array
     */
    public function getBindingStatus(): array
    {
        $settings = WechatRoomBindingSetting::getSettings();
        return $settings->toApiArray();
    }

    /**
     * 更新微信群组绑定状态
     *
     * @param array $data
     * @return WechatRoomBindingSetting
     * @throws Exception
     */
    public function updateBindingStatus(array $data): WechatRoomBindingSetting
    {
        try {
            DB::beginTransaction();

            $settings = WechatRoomBindingSetting::getSettings();
            $oldEnabled = $settings->enabled;

            $settings->update([
                'enabled' => $data['enabled'],
                'auto_assign' => $data['autoAssign'] ?? $settings->auto_assign,
                'default_room_id' => $data['defaultRoomId'] ?? $settings->default_room_id,
                'max_plans_per_room' => $data['maxPlansPerRoom'] ?? $settings->max_plans_per_room,
            ]);

            // 如果从启用状态变为禁用状态，清空未完成计划的绑定关系
            if ($oldEnabled && !$data['enabled']) {
                // 获取所有未完成的计划ID
                $unfinishedPlanIds = DB::table('charge_plans')
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->pluck('id');

                // 删除这些计划的绑定关系
                if ($unfinishedPlanIds->isNotEmpty()) {
                    ChargePlanWechatRoomBinding::whereIn('plan_id', $unfinishedPlanIds)->delete();
                }
            }

            DB::commit();

            return $settings;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update wechat room binding status: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取微信群组列表
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function getWechatRooms(array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $pageSize = $params['pageSize'] ?? 20;
            $search = $params['search'] ?? null;
            $keyword = $params['keyword'] ?? null;

            $query = DB::connection('mysql_card')
                ->table('mr_room')
                ->where('is_del', 0);

            // 搜索条件
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('room_name', 'like', "%{$search}%")
                      ->orWhere('room_id', 'like', "%{$search}%");
                });
            }

            // 关键词搜索
            if ($keyword) {
                $query->where('room_name', 'like', "%{$keyword}%");
            }

            // 获取总数
            $total = $query->count();

            // 分页查询
            $rooms = $query->orderBy('id', 'desc')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            // 转换为API格式并添加计划数量
            $list = $rooms->map(function ($room) {
                $planCount = ChargePlanWechatRoomBinding::where('room_id', $room->room_id)->count();

                return [
                    'id' => (string)$room->id,
                    'roomId' => $room->room_id,
                    'roomName' => $room->room_name ?? '未知群组',
                    'memberCount' => $room->member_count ?? 0,
                    'isActive' => $room->is_active ?? true,
                    'planCount' => $planCount,
                ];
            });

            return [
                'list' => $list,
                'total' => $total,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get wechat rooms: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 绑定计划到微信群组
     *
     * @param string $planId
     * @param string $roomId
     * @return ChargePlanWechatRoomBinding
     * @throws Exception
     */
    public function bindPlanToRoom(string $planId, string $roomId): ChargePlanWechatRoomBinding
    {
        try {
            DB::beginTransaction();

            // 检查计划是否存在
            $plan = ChargePlan::findOrFail($planId);

            // 检查群组是否存在
            $room = DB::connection('mysql_card')
                ->table('mr_room')
                ->where('room_id', $roomId)
                ->first();

            if (!$room) {
                throw new Exception('微信群组不存在');
            }

            // 检查是否已经绑定
            $existingBinding = ChargePlanWechatRoomBinding::where('plan_id', $planId)->first();
            if ($existingBinding) {
                throw new Exception('该计划已经绑定到其他群组');
            }

            // 检查群组计划数量限制
            $settings = WechatRoomBindingSetting::getSettings();
            $currentCount = ChargePlanWechatRoomBinding::where('room_id', $roomId)->count();

//            if ($currentCount >= $settings->max_plans_per_room) {
//                throw new Exception("该群组已达到最大计划数限制（{$settings->max_plans_per_room}个）");
//            }

            // 创建绑定
            $binding = ChargePlanWechatRoomBinding::create([
                'plan_id' => $planId,
                'room_id' => $roomId,
                'bound_at' => Carbon::now(),
            ]);

            DB::commit();

            return $binding;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to bind plan to wechat room: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 解绑计划的微信群组
     *
     * @param string $planId
     * @return bool
     * @throws Exception
     */
    public function unbindPlanFromRoom(string $planId)
    {
        try {
            $binding = ChargePlanWechatRoomBinding::where('plan_id', $planId)->first();

            if (!$binding) {
                throw new Exception('该计划未绑定任何群组');
            }

            $binding->delete();

            return true;
        } catch (Exception $e) {
            Log::error('Failed to unbind plan from wechat room: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 批量绑定计划到微信群组
     *
     * @param string $roomId
     * @param array $planIds
     * @return array
     */
    public function batchBindPlansToRoom(string $roomId, array $planIds): array
    {
        $successCount = 0;
        $failCount = 0;
        $results = [];

        foreach ($planIds as $planId) {
            try {
                $binding = $this->bindPlanToRoom($planId, $roomId);
                $successCount++;
                $results[] = [
                    'planId' => $planId,
                    'status' => 'success',
                    'message' => '绑定成功',
                ];
            } catch (Exception $e) {
                $failCount++;
                $results[] = [
                    'planId' => $planId,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'successCount' => $successCount,
            'failCount' => $failCount,
            'results' => $results,
        ];
    }

    /**
     * 批量解绑计划的微信群组
     *
     * @param array $planIds
     * @return array
     */
    public function batchUnbindPlansFromRoom(array $planIds): array
    {
        $successCount = 0;
        $failCount = 0;
        $results = [];

        foreach ($planIds as $planId) {
            try {
                $this->unbindPlanFromRoom($planId);
                $successCount++;
                $results[] = [
                    'planId' => $planId,
                    'status' => 'success',
                    'message' => '解绑成功',
                ];
            } catch (Exception $e) {
                $failCount++;
                $results[] = [
                    'planId' => $planId,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'successCount' => $successCount,
            'failCount' => $failCount,
            'results' => $results,
        ];
    }

    /**
     * 获取微信群组执行统计
     *
     * @param string|null $roomId
     * @return array
     * @throws Exception
     */
    public function getWechatRoomStats(string $roomId = null): array
    {
        try {
            // 总体统计
            $totalRooms = DB::connection('mysql_card')->table('mr_room')->count();
            $activeRooms = DB::connection('mysql_card')->table('mr_room')
                ->where('is_active', 1)
                ->count();

            $totalPlans = ChargePlan::count();
            $activePlans = ChargePlan::where('status', 'processing')->count();
            $completedPlans = ChargePlan::where('status', 'completed')->count();

            // 群组统计 - 分别查询避免跨数据库JOIN问题
            $roomsQuery = DB::connection('mysql_card')->table('mr_room');
            if ($roomId) {
                $roomsQuery->where('room_id', $roomId);
            }
            $rooms = $roomsQuery->get();

            $roomStats = $rooms->map(function ($room) {
                // 获取该群组的绑定计划
                $bindings = ChargePlanWechatRoomBinding::where('room_id', $room->room_id)
                    ->with('plan')
                    ->get();

                $planCount = $bindings->count();
                $completedCount = $bindings->filter(function ($binding) {
                    return $binding->plan && $binding->plan->status === 'completed';
                })->count();

                $totalAmount = $bindings->sum(function ($binding) {
                    return $binding->plan ? $binding->plan->total_amount : 0;
                });

                $chargedAmount = $bindings->sum(function ($binding) {
                    return $binding->plan ? $binding->plan->charged_amount : 0;
                });

                $progress = $totalAmount > 0 ? ($chargedAmount / $totalAmount) * 100 : 0;

                return [
                    'roomId' => $room->room_id,
                    'roomName' => $room->room_name ?? '未知群组',
                    'planCount' => $planCount,
                    'completedCount' => $completedCount,
                    'totalAmount' => (float)$totalAmount,
                    'chargedAmount' => (float)$chargedAmount,
                    'progress' => round($progress, 2),
                ];
            });

            return [
                'totalRooms' => $totalRooms,
                'activeRooms' => $activeRooms,
                'totalPlans' => $totalPlans,
                'activePlans' => $activePlans,
                'completedPlans' => $completedPlans,
                'roomStats' => $roomStats,
            ];
        } catch (Exception $e) {
            Log::error('Failed to get wechat room stats: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 自动分配计划到群组
     *
     * @param ChargePlan $plan
     * @return bool
     */
    public function autoAssignPlanToRoom(ChargePlan $plan): bool
    {
        try {
            $settings = WechatRoomBindingSetting::getSettings();

            if (!$settings->enabled) {
                return false;
            }

            // 查找计划数量最少的群组 - 分别查询避免跨数据库JOIN问题
            $activeRooms = DB::connection('mysql_card')
                ->table('mr_room')
                ->where('is_del', 0)
                ->get();

            $roomWithMinPlans = null;
            $minPlanCount = $settings->max_plans_per_room;

            foreach ($activeRooms as $room) {
                $planCount = ChargePlanWechatRoomBinding::where('room_id', $room->room_id)->count();

                if ($planCount < $minPlanCount) {
                    $minPlanCount = $planCount;
                    $roomWithMinPlans = $room;
                }
            }

            if ($roomWithMinPlans) {
                $this->bindPlanToRoom($plan->id, $roomWithMinPlans->room_id);
                return true;
            }

            return false;
        } catch (Exception $e) {
            Log::error('Failed to auto assign plan to room: ' . $e->getMessage());
            return false;
        }
    }
}
