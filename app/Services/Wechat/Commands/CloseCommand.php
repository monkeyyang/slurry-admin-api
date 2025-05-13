<?php

namespace App\Services\Wechat\Commands;

use App\Services\Wechat\WechatService;

class CloseCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $wechatService = new WechatService($input['data']['room_wxid'], $input['data']['from_wxid'], $input['data']['msgid'], $input['data']['msg']);
        $wechatService->closeWechatBot();
        return 'success';
    }
}
