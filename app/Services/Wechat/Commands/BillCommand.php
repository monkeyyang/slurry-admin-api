<?php

namespace App\Services\Wechat\Commands;

use App\Services\Wechat\WechatService;

/**
 * 账单命令处理类
 *
 * 负责处理微信群聊中的账单添加命令，包括验证群状态、
 * 消息去重、消息解析和账单执行
 */
class BillCommand implements CommandStrategy
{
    /**
     * 执行账单命令
     *
     * @param array $input 输入数据
     * @return bool 执行成功返回true，否则返回false
     * @throws \Exception 执行过程中可能抛出异常
     */
    public function execute(array $input): bool
    {
        try {
            $wechatService = new WechatService($input);
            
            // 验证前置条件(群聊是否已开启机器人、消息是否已处理)
            if (!$wechatService->validatePreConditions()) {
                return false;
            }
            
            // 检查是否为补单
            $isForceBilled = $this->isSupplementOrder($input['data']['msg']);
            if ($isForceBilled) {
                // 去除最后的 #补单 标记
                $input['data']['msg'] = str_replace('#补单', '', $input['data']['msg']);
                $wechatService->setForceBilled();
            }
            
            // 执行账单处理
            $bill = $wechatService->billed();
            if (empty($bill)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // 使用WechatService的logError方法记录异常
            (new WechatService($input))->logError('账单命令执行异常: ' . $e->getMessage(), $input);
            return false;
        } catch (\Error $e) {
            // 捕获PHP错误
            (new WechatService($input))->logError('账单命令执行错误: ' . $e->getMessage(), $input);
            return false;
        } catch (\Throwable $e) {
            // 捕获所有其他可能的错误
            (new WechatService($input))->logError('账单命令执行严重错误: ' . $e->getMessage(), $input);
            return false;
        }
    }

    /**
     * 判断是否为补单指令
     *
     * @param string $message 消息内容
     * @return bool 是补单指令返回true，否则返回false
     */
    private function isSupplementOrder(string $message): bool
    {
        $marker = "#补单";
        return str_ends_with($message, $marker);
    }
}
