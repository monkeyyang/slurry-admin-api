<?php

namespace App\Services\Wechat\MessageHandler;

use app\common\service\MessageParser;
use App\Services\Wechat\CommandInvoker;
use App\Services\Wechat\Commands\CodeCommand;
use App\Services\Wechat\Common;

class RecvOtherAppMessageHandler implements MessageHandler
{

    /**
     * @throws \Exception
     */
    public function handle($input): bool
    {
        // 解析XML
        $xml = simplexml_load_string($input['data']['raw_msg']);
        // 将SimpleXML对象转换为JSON字符串
        $jsonString = json_encode($xml);
        // 将JSON字符串解码为数组
        $xmlArray = json_decode($jsonString, true);
        if (empty($xmlArray['appmsg']['title'])) return false;
        // 获取发送的消息
        $msg = $xmlArray['appmsg']['title'];
        // 获取引用的消息
        $referMsg = $xmlArray['appmsg']['refermsg'];
        // 确认增加或扣除（账单指令必须为+或-开头的字符，且后边跟着的必须为数字）
        $msg  = str_replace('&nbsp;', ' ', htmlentities(trim($msg)));
        $type = $msg[0];
        // 非加账消息且非机器人发出的消息，识别卡密保存
        if ($type != '+' && $type != '-' && $input['data']['from_wxid'] != 'wxid_2dck7u3qmnox12' && $input['data']['from_wxid'] != 'wxid_aiv8hxjw87z012') {
            $input['data']['msg'] = $referMsg;
            $invoker              = new CommandInvoker();
            $invoker->addCommand('/代码', new CodeCommand());
            $invoker->executeCommand('/代码', $input);
        } else {
            if (!empty($referMsg['content'])) $msg .= '#' . $referMsg['content']; // 引用记账不在解析卡密
            // 账单流程
            $parser = new MessageParser($input['data']['room_wxid'], $input['data']['from_wxid'], $input['data']['msgid'], $msg);
            $parser->setClientId($input['client_id']);
            $parser->setCreatedAt($input['data']['timestamp']);
            $parser->setManager();
            $parser->parseAndSave();
        }

        return true;
    }
}
