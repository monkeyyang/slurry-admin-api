<?php

namespace app\common\WechatMsg\MessageHandler;

use app\common\WechatMsg\Common;
use think\facade\Cache;

class WechatLogoutHandler implements MessageHandler
{

    public function handle($input)
    {
        // 登出微信，清空登录信息
        $cacheKey = Common::getCacheKey('wechat_login_wxid');
        Cache::store('redis')->set($cacheKey, '');
    }
}