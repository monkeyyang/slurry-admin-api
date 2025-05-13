<?php

namespace app\common\WechatMsg\Commands;

use app\common\model\Room;
use app\common\service\EnhancedMessageParser;
use app\common\WechatMsg\Common;

/**
 * 增强版账单命令处理类
 * 
 * 使用增强版消息解析器处理微信群聊中的账单添加命令
 */
class EnhancedBillCommand implements CommandStrategy
{
    /**
     * 执行账单命令
     *
     * @param array $input 输入数据
     * @return bool 执行成功返回true，否则返回false
     */
    public function execute(array $input): bool
    {
        try {
            // 验证前置条件
            if (!$this->validatePreConditions($input)) {
                return false;
            }
            
            // 处理消息内容
            $input = $this->processMessageContent($input);
            
            // 创建并配置消息解析器
            $parser = $this->createMessageParser($input);
            
            // 执行账单处理
            return $parser->exec();
        } catch (\Exception $e) {
            // 记录异常信息到日志
            $this->logError('命令执行异常: ' . $e->getMessage(), $input);
            return false;
        } catch (\Error $e) {
            // 捕获PHP错误
            $this->logError('命令执行错误: ' . $e->getMessage(), $input);
            return false;
        } catch (\Throwable $e) {
            // 捕获所有其他可能的错误
            $this->logError('命令执行严重错误: ' . $e->getMessage(), $input);
            return false;
        }
    }
    
    /**
     * 验证前置条件
     *
     * @param array $input 输入数据
     * @return bool 验证通过返回true，否则返回false
     */
    private function validatePreConditions(array $input): bool
    {
        // 检查群是否开启记账功能
        if (!Room::isOpen($input['data']['room_wxid'])) {
            return false;
        }
        
        // 检查消息是否已经处理过
        if ($this->isMessageAlreadyHandled($input['data']['msgid'])) {
            return false;
        }
        
        return true;
    }


    /**
     * 检查消息是否已经处理过
     *
     * @param string $msgId 消息ID
     * @return bool 消息已处理返回true，否则返回false
     */
    private function isMessageAlreadyHandled(string $msgId): bool
    {
        $record = db('room_event')->where('msgid', $msgId)->find();
        return !empty($record);
    }
    
    /**
     * 处理消息内容
     *
     * @param array $input 输入数据
     * @return array 处理后的输入数据
     */
    private function processMessageContent(array $input): array
    {
        // 清理HTML实体和空格
        $input['data']['msg'] = str_replace('&nbsp;', ' ', htmlentities(trim($input['data']['msg'])));
        $input['data']['msg'] = trim($input['data']['msg']);
        
        return $input;
    }
    
    /**
     * 创建并配置消息解析器
     *
     * @param array $input 输入数据
     * @return EnhancedMessageParser 配置好的消息解析器实例
     */
    private function createMessageParser(array $input): EnhancedMessageParser
    {
        $parser = new EnhancedMessageParser(
            $input['data']['room_wxid'], 
            $input['data']['from_wxid'], 
            $input['data']['msgid'], 
            $input['data']['msg']
        );
        
        // 设置解析器参数
        $parser->setClientId($input['client_id'])
               ->setCreatedAt($input['data']['timestamp'])
               ->setManager();
        
        // 设置账单数据（如果有预设）
        if (!empty($input['data']['bill'])) {
            $parser->setBill($input['data']['bill']);
        }
        
        // 检查是否为补单指令
        if ($this->isSupplementOrder($input['data']['msg'])) {
            $parser->setPatchOrder(true);
        }
        
        return $parser;
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
        return substr($message, -strlen($marker)) === $marker;
    }
    
    /**
     * 记录错误信息
     *
     * @param string $errorMessage 错误消息
     * @param array $context 错误上下文
     * @return void
     */
    private function logError(string $errorMessage, array $context): void
    {
        // 提取关键信息，避免日志过于冗长
        $logContext = [
            'client_id' => $context['client_id'] ?? 'unknown',
            'room_id' => $context['data']['room_wxid'] ?? 'unknown',
            'from_id' => $context['data']['from_wxid'] ?? 'unknown',
            'msg_id' => $context['data']['msgid'] ?? 'unknown',
            'message' => substr($context['data']['msg'] ?? '', 0, 100) . (strlen($context['data']['msg'] ?? '') > 100 ? '...' : '')
        ];
        
        // 使用Common日志类记录错误
        Common::log('error', 'bill_command', $errorMessage, $logContext);
        
        // 记录详细堆栈跟踪以便调试严重问题
        if (strpos($errorMessage, 'Exception') !== false || strpos($errorMessage, 'Error') !== false) {
            Common::log('error', 'bill_exception', "详细错误: " . $errorMessage . "\n堆栈跟踪: " . 
                (new \Exception())->getTraceAsString(), $logContext);
        }
    }
} 