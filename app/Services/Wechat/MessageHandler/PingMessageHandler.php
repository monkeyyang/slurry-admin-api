<?php

namespace app\common\WechatMsg\MessageHandler;

class PingMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        // TODO: Implement handle() method.
        trace($input);
    }
}