<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GiftCardApiClient
{
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('gift_card.api_base_url', 'http://172.16.229.189:8080/api');
    }

    /**
     * 创建登录任务
     *
     * @param array $accounts 账号列表
     * @return array 包含task_id的响应
     */
    public function createLoginTask(array $accounts): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/login_poll/new", [
                'list' => $accounts
            ]);

            if (!$response->successful()) {
                throw new \Exception('创建登录任务失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('创建登录任务异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询登录任务状态
     *
     * @param string $taskId 任务ID
     * @return array 任务状态
     */
    public function getLoginTaskStatus(string $taskId): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/login_poll/status", [
                'task_id' => $taskId
            ]);

            if (!$response->successful()) {
                throw new \Exception('查询登录任务状态失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('查询登录任务状态异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 删除用户登录
     *
     * @param array $accounts 需要删除的账号列表
     * @return array 删除结果
     */
    public function deleteUserLogins(array $accounts): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/del_users", [
                'list' => $accounts
            ]);

            if (!$response->successful()) {
                throw new \Exception('删除用户登录失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('删除用户登录异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 强制刷新用户信息
     *
     * @param array $account 账号信息
     * @return array 刷新结果
     */
    public function refreshUserLogin(array $account): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/refresh_login", $account);

            if (!$response->successful()) {
                throw new \Exception('刷新用户登录失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('刷新用户登录异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 创建查卡任务
     *
     * @param array $cards 卡号列表
     * @return array 包含task_id的响应
     */
    public function createCardQueryTask(array $cards): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/batch_query/new", [
                'list' => $cards
            ]);

            if (!$response->successful()) {
                throw new \Exception('创建查卡任务失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('创建查卡任务异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询查卡任务状态
     *
     * @param string $taskId 任务ID
     * @return array 任务状态
     */
    public function getCardQueryTaskStatus(string $taskId): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/batch_query/status", [
                'task_id' => $taskId
            ]);

            if (!$response->successful()) {
                throw new \Exception('查询查卡任务状态失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('查询查卡任务状态异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 创建兑换任务
     *
     * @param array $redemptions 兑换信息列表
     * @param int $interval 同一账户兑换多张卡时的时间间隔
     * @return array 包含task_id的响应
     */
    public function createRedemptionTask(array $redemptions, int $interval = 6): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/redeem/new", [
                'list' => $redemptions,
                'interval' => $interval
            ]);

            if (!$response->successful()) {
                throw new \Exception('创建兑换任务失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('创建兑换任务异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询兑换任务状态
     *
     * @param string $taskId 任务ID
     * @return array 任务状态
     */
    public function getRedemptionTaskStatus(string $taskId): array
    {
        try {
            $response = Http::get("{$this->baseUrl}/redeem/status", [
                'task_id' => $taskId
            ]);

            if (!$response->successful()) {
                throw new \Exception('查询兑换任务状态失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('查询兑换任务状态异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询查卡历史记录
     *
     * @param string $keyword 关键词
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 历史记录
     */
    public function getCardQueryHistory(string $keyword = '', string $startTime = '', string $endTime = '', int $page = 1, int $pageSize = 20): array
    {
        try {
            if (empty($startTime)) {
                $startTime = now()->subDay()->format('Y-m-d H:i:s');
            }
            if (empty($endTime)) {
                $endTime = now()->format('Y-m-d H:i:s');
            }

            $response = Http::post("{$this->baseUrl}/query_log", [
                'keyword' => $keyword,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'page' => $page,
                'page_size' => $pageSize
            ]);

            if (!$response->successful()) {
                throw new \Exception('查询查卡历史记录失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('查询查卡历史记录异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询兑换历史记录
     *
     * @param string $keyword 关键词
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array 历史记录
     */
    public function getRedemptionHistory(string $keyword = '', string $startTime = '', string $endTime = '', int $page = 1, int $pageSize = 20): array
    {
        try {
            if (empty($startTime)) {
                $startTime = now()->subDay()->format('Y-m-d H:i:s');
            }
            if (empty($endTime)) {
                $endTime = now()->format('Y-m-d H:i:s');
            }

            $response = Http::post("{$this->baseUrl}/redeem_log", [
                'keyword' => $keyword,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'page' => $page,
                'page_size' => $pageSize
            ]);

            if (!$response->successful()) {
                throw new \Exception('查询兑换历史记录失败: ' . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('查询兑换历史记录异常: ' . $e->getMessage());
            return [
                'code' => -1,
                'msg' => $e->getMessage(),
                'data' => null
            ];
        }
    }
}
