<?php

namespace app\common\WechatMsg;

use app\common\WechatMsg\Commands\CommandStrategy;

class CommandInvoker {
    private $commands = [];

    public function addCommand($keyword, CommandStrategy $command) {
        $this->commands[$keyword] = $command;
    }

    public function executeCommand($keyword, $input) {
        if (isset($this->commands[$keyword])) {
            $this->commands[$keyword]->execute($input);
        }
    }
}