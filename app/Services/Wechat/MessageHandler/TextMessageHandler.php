<?php

namespace App\Services\Wechat\MessageHandler;

use App\Services\Wechat\CommandInvoker;
use App\Services\Wechat\Commands\AddManagerCommand;
use App\Services\Wechat\Commands\BillCommand;
use App\Services\Wechat\Commands\ClearCommand;
use App\Services\Wechat\Commands\CloseCommand;
use App\Services\Wechat\Commands\CodeCommand;
use App\Services\Wechat\Commands\InCardCommand;
use App\Services\Wechat\Commands\IntegrateCommand;
use App\Services\Wechat\Commands\OpenCommand;
use App\Services\Wechat\Commands\OutCardCommand;
use App\Services\Wechat\Commands\QueryCodeCommand;
use App\Services\Wechat\Commands\QueryCommand;
use App\Services\Wechat\Commands\RecallCommand;
use App\Services\Wechat\Common;
use Illuminate\Support\Facades\Log;

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
    private const CMD_RECALL = '/撤回';
    private const CMD_BILL_TOTAL = '/账单总额';
    private const CMD_BILL_AMOUNT = '/账单金额';
    private const CMD_GIFT_CHARGE = '/礼品卡兑换';
    private const CMD_GET_VERIFY_CODE = '/查码';

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
        Log::channel('card_query')->info("群聊ID: {$input['data']['room_wxid']}，发送人微信ID：{$input['data']['from_wxid']}，消息内容：{$input['data']['msg']}");

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
     * 通用前置验证
     *  todo 群聊是否开启机器人，消息发送者是否拥有对应权限，消息是否已处理
     * @return void
     */
    private function preValidate()
    {

    }

    /**
     * 数据合法性校验
     *
     * @return void
     */
    private function ensureValid()
    {

    }

    /**
     * 加账前校验
     * @return void
     */
    private function checkBeforeAddBill() {

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
            self::CMD_GIFT_CHARGE => new BillCommand()
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
        if (str_starts_with($input['data']['msg'], '/添加')) {
            return self::CMD_ADD;
        }

        // 检查是否为礼品卡兑换消息
        $chargeData = $this->extractChargeData($input['data']['msg']);
        if(!empty($chargeData)) {
            $input['data']['extractData'] = $chargeData;
            return self::CMD_GIFT_CHARGE;
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
            self::CMD_IN_CARD,
            self::CMD_RECALL
        ];

        return in_array($msg, $directCommands);
    }

    /**
     * 提取礼品卡兑换数据
     *
     * @param string $message
     * @return array|false
     */
    private function extractChargeData(string $message): bool|array
    {
        // 清理消息内容
        $cleanMsg = str_replace('&nbsp;', ' ', htmlentities(trim($message)));

        // 定义礼品卡兑换消息的正则匹配模式
        // 匹配格式: X开头的16位字母数字组合 + 空格 + /1 或 /0
        $pattern = '/^X[A-Z0-9]{15}\s+\/([01])$/';

        if (preg_match($pattern, $cleanMsg, $matches)) {
            return [
                'mode' => 'gift_card_redemption',
                'card_code' => substr($cleanMsg, 0, 16), // 提取16位卡号
                'type' => $matches[1], // 提取类型 (0 或 1)
                'original_message' => $message
            ];
        }

        return false;
    }

    /**
     * 提取账单数据
     *
     * 判断消息是否为加账信息，并提取相关数据
     *
     * @param string $message 消息内容
     * @return array|bool 如果是加账信息返回结构化数据，否则返回false
     */
    private function extractBillData(string $message): array|bool
    {
        // 清理消息内容
        $cleanMsg = str_replace('&nbsp;', ' ', htmlentities(trim($message)));

        // 定义加账信息的正则匹配模式
        $patterns = [
            // 模式1: "加账 xxxx 100" 或 "添加 xxxx 100" 或 "代加 xxxx 100"
            '/^(加账|添加|代加)\s+([^\s]+)\s+(\d+(\.\d+)?)$/i',

            // 模式2: "给xxxx加100" 或 "给xxxx添加100"
            '/^给\s*([^\s]+)\s*(加|添加)\s*(\d+(\.\d+)?)$/i',

            // 模式3: "xxxx+100" 或 "xxxx 加 100"
            '/^([^\s\+]+)\s*[\+加]\s*(\d+(\.\d+)?)$/i',
        ];

        // 遍历匹配模式
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleanMsg, $matches)) {
                // 根据不同模式提取数据
                if (count($matches) >= 4) {
                    // 提取账户和金额
                    $account = isset($matches[2]) ? $matches[2] : $matches[1];
                    $amount = isset($matches[3]) ? floatval($matches[3]) : floatval($matches[2]);

                    // 返回结构化数据
                    return [
                        'type' => 'add_balance',
                        'account' => $account,
                        'amount' => $amount,
                        'original_message' => $message
                    ];
                }
            }
        }

        // 如果还需要其他方式识别加账格式，可以在这里添加处理逻辑
        // 例如检查是否包含特定关键词组合等
        $keywords = ['加钱', '增加余额', '充值'];
        foreach ($keywords as $keyword) {
            if (strpos($cleanMsg, $keyword) !== false) {
                // 尝试提取数字
                if (preg_match('/(\d+(\.\d+)?)/', $cleanMsg, $matches)) {
                    // 简单提取可能的账户名（假设在金额前的第一个词）
                    $parts = explode($matches[0], $cleanMsg);
                    $accountPart = trim($parts[0]);
                    $words = preg_split('/\s+/', $accountPart);
                    $account = end($words);

                    return [
                        'type' => 'add_balance',
                        'account' => $account,
                        'amount' => floatval($matches[0]),
                        'original_message' => $message,
                        'uncertain' => true // 标记为不确定提取，可能需要人工确认
                    ];
                }
            }
        }

        // 如果不匹配任何加账模式，返回false
        return false;
    }
}
