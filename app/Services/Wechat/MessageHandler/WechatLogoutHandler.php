<?php

namespace App\Services\Wechat\MessageHandler;

use App\Services\Wechat\Common;
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
