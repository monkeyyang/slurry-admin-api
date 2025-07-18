<?php

namespace App\Services\Gift;

use App\Services\GiftCardApiClient;
use RuntimeException;

class TaskStatusCheckerService
{
    private string $taskId;
    private int $maxAttempts;
    private int $attemptInterval;
    private GiftCardApiClient $apiClient;

    public function __construct(string $taskId, int $maxAttempts = 60, int $interval = 1)
    {
        $this->taskId = $taskId;
        $this->maxAttempts = $maxAttempts;
        $this->attemptInterval = $interval;
        $this->apiClient = new GiftCardApiClient();
    }

    public function createTask(array $codes)
    {

    }

    /**
     * 持续检查任务状态直到完成
     *
     * @return array 返回API的完整JSON响应数据
     * @throws RuntimeException 当超过最大尝试次数后抛出异常
     */
    public function checkUntilCompleted(): array
    {
        $attempts = 0;

        while ($attempts < $this->maxAttempts) {
            $startTime = microtime(true);
            $attempts++;

            try {
                $responseData = $this->makeStatusRequest();

                if ($responseData['code'] === 1) { // 成功响应
                    if ($this->isTaskCompleted($responseData)) {
                        return $responseData; // 返回完整的响应数据
                    }
                }
            } catch (\Exception $e) {
                $this->logError($e, $attempts);
            }

            $this->sleepUntilNextAttempt($startTime);
        }

        $this->throwTimeoutException($attempts);
    }

    /**
     * 发起状态检查请求
     */
    private function makeStatusRequest(): array
    {
        return $this->apiClient->getCardQueryTaskStatus($this->taskId);
    }

    /**
     * 判断任务是否完成
     */
    private function isTaskCompleted(array $responseData): bool
    {
        return isset($responseData['data']['status']) &&
               $responseData['data']['status'] === 'completed';
    }

    /**
     * 计算并等待到下一次尝试
     */
    private function sleepUntilNextAttempt(float $startTime): void
    {
        $elapsed = microtime(true) - $startTime;
        $sleepTime = max(0, $this->attemptInterval - $elapsed);

        if ($sleepTime > 0) {
            usleep((int) ($sleepTime * 1000000));
        }
    }

    /**
     * 记录错误日志
     */
    private function logError(\Exception $e, int $attempt): void
    {
        logger()->error("Task status check failed (attempt {$attempt})", [
            'task_id' => $this->taskId,
            'error' => $e->getMessage(),
            'exception' => get_class($e)
        ]);
    }

    /**
     * 抛出超时异常
     */
    private function throwTimeoutException(int $attempts): void
    {
        $message = sprintf(
            'Task status check timed out after %d attempts (task_id: %s)',
            $attempts,
            $this->taskId
        );

        throw new RuntimeException($message);
    }
}
