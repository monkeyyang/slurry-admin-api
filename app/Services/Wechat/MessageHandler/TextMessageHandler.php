<?php

namespace app\common\WechatMsg\MessageHandler;

use app\common\WechatMsg\CommandInvoker;
use app\common\WechatMsg\Commands\AddManagerCommand;
use app\common\WechatMsg\Commands\BillCommand;
use app\common\WechatMsg\Commands\ClearCommand;
use app\common\WechatMsg\Commands\CloseCommand;
use app\common\WechatMsg\Commands\CodeCommand;
use app\common\WechatMsg\Commands\InCardCommand;
use app\common\WechatMsg\Commands\IntegrateCommand;
use app\common\WechatMsg\Commands\OpenCommand;
use app\common\WechatMsg\Commands\OutCardCommand;
use app\common\WechatMsg\Commands\QueryCodeCommand;
use app\common\WechatMsg\Commands\QueryCommand;
use app\common\WechatMsg\Commands\RecallCommand;
use app\common\WechatMsg\Common;

class TextMessageHandler implements MessageHandler
{
    /**
     * 命令常量定义
     */
    private const CMD_OPEN = '/开启';
    private const CMD_CLOSE = '/关闭';
    private const CMD_QUERY = '/查询';
    private const CMD_INTEGRATE = '/整合';
    private const CMD_CLEAR = '/清账';
    private const CMD_CLEAR_ALT = '/清帐';
    private const CMD_ADD = '/添加';
    private const CMD_OUT_CARD = '/出卡';
    private const CMD_IN_CARD = '/收卡';
    private const CMD_CODE = '/代码';
    private const CMD_BILL = '/账单';
    private const CMD_BILL_TOTAL = '/账单总额';
    private const CMD_BILL_AMOUNT = '/账单金额';

    /**
     * @var CommandInvoker|null
     */
    private ?CommandInvoker $invoker = null;

    /**
     * 处理消息入口
     * 
     * @param array $input 消息输入数据
     * @return void
     */
    public function handle($input): void
    {
        // 记录日志
//        Common::log($input['data']['room_wxid'], $input['data']['from_wxid'], $input['data']['msg']);
        
        // 预处理消息
        $input['data']['msg'] = $this->preprocessMessage($input['data']['msg']);
        $originalMsg = strtolower(trim($input['data']['msg']));

        // 确定并执行命令
        $command = $this->determineCommand($originalMsg, $input);
        $this->initializeInvoker()->executeCommand($command, $input);
    }
    
    /**
     * 预处理消息文本
     * 
     * @param string $message 原始消息
     * @return string 处理后的消息
     */
    private function preprocessMessage(string $message): string
    {
        // 将Cyrillic字符P转换成英文字母P并转为大写
        return str_replace('Р', "P", strtoupper($message));
    }
    
    /**
     * 初始化命令执行器并注册所有命令
     * 
     * @return CommandInvoker 命令执行器实例
     */
    private function initializeInvoker(): CommandInvoker
    {
        if ($this->invoker === null) {
            $this->invoker = new CommandInvoker();
            
            // 注册所有可用命令
            $this->registerCommands();
        }
        
        return $this->invoker;
    }
    
    /**
     * 注册所有命令
     */
    private function registerCommands(): void
    {
        $commandMap = [
            self::CMD_OPEN => new OpenCommand(),
            self::CMD_CLOSE => new CloseCommand(),
            self::CMD_OUT_CARD => new OutCardCommand(),
            self::CMD_IN_CARD => new InCardCommand(),
            self::CMD_CODE => new QueryCodeCommand(),
            self::CMD_BILL => new BillCommand(),
            self::CMD_QUERY => new QueryCommand(),
            self::CMD_BILL_TOTAL => new QueryCommand(),
            self::CMD_BILL_AMOUNT => new QueryCommand(),
            self::CMD_INTEGRATE => new IntegrateCommand(),
            self::CMD_CLEAR => new ClearCommand(),
            self::CMD_ADD => new AddManagerCommand(),
        ];

        foreach ($commandMap as $command => $handler) {
            $this->invoker->addCommand($command, $handler);
        }
    }
    
    /**
     * 根据消息内容确定要执行的命令
     * 
     * @param string $msg 原始消息内容
     * @param array $input 输入数据
     * @return string 命令名称
     */
    private function determineCommand(string $msg, array &$input): string
    {
        // 直接处理特定命令
        if ($this->isDirectCommand($msg)) {
            return $msg;
        }

        // 检查是否为添加信息
        if (strpos($input['data']['msg'], '/添加') === 0) {
            return self::CMD_ADD;
        }
        
        // 检查是否为账单信息
        $billData = $this->extractBillData($input['data']['msg']);
        if (!empty($billData)) {
            $input['data']['bill'] = $billData;
            return self::CMD_BILL;
        }
        
        // 默认情况下，视为代码查询
        return self::CMD_CODE;
    }
    
    /**
     * 判断是否为直接支持的命令
     * 
     * @param string $msg 命令消息
     * @return bool 是否为直接命令
     */
    private function isDirectCommand(string $msg): bool
    {
        $directCommands = [
            self::CMD_OPEN, 
            self::CMD_CLOSE,
            self::CMD_QUERY,
            self::CMD_INTEGRATE,
            self::CMD_CLEAR,
            self::CMD_CLEAR_ALT,
            self::CMD_ADD,
            self::CMD_OUT_CARD,
            self::CMD_IN_CARD
        ];
        
        return in_array($msg, $directCommands);
    }
    
    /**
     * 提取账单数据
     * 
     * @param string $message 消息内容
     * @return array 账单数据，如果不是账单则为空数组
     */
    private function extractBillData(string $message): array
    {
        $cleanMsg = str_replace('&nbsp;', ' ', htmlentities(trim($message)));
        return Common::parserInputMsg($cleanMsg) ?: [];
    }
}