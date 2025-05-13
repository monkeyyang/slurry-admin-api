<?php

namespace app\common\WechatMsg\MessageHandler;

use app\common\WechatMsg\Common;
use think\facade\Cache;

class UserLoginMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        // 登入微信，记录当前登录机器人wxid到Redis
        $cacheKey = Common::getCacheKey('wechat_login_wxid');
        Cache::store('redis')->set($cacheKey, $input['wxid']);
    }
}