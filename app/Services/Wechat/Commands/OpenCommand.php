<?php

namespace App\Services\Wechat\Commands;

use App\Services\Wechat\WechatService;

class OpenCommand implements CommandStrategy
{
    public function execute($input): string
    {
        $wechatService = new WechatService($input['data']['room_wxid'], $input['data']['from_wxid'], $input['data']['msgid'], $input['data']['msg']);
        $wechatService->openWechatBot();
        return 'success';
    }
}
