<?php

namespace app\common\WechatMsg\Commands;

interface CommandStrategy {
    public function execute(array $input);
}
