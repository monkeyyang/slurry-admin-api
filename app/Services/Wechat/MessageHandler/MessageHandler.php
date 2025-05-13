<?php

namespace App\Services\Wechat\MessageHandler;

interface MessageHandler
{
    public function handle($input);
}
