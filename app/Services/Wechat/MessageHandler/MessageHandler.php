<?php

namespace app\common\WechatMsg\MessageHandler;

interface MessageHandler
{
    public function handle($input);
}