<?php

namespace App\Services\Wechat;

use App\Services\Wechat\Commands\CommandStrategy;

class CommandInvoker {
    private array $commands = [];

    /**
     * 添加命令
     *
     * @param $keyword
     * @param CommandStrategy $command
     * @return void
     */
    public function addCommand($keyword, CommandStrategy $command): void
    {
        $this->commands[$keyword] = $command;
    }

    /**
     * 执行命令
     *
     * @param $keyword
     * @param $input
     * @return void
     */
    public function executeCommand($keyword, $input): void
    {
        if (isset($this->commands[$keyword])) {
            $this->commands[$keyword]->execute($input);
        }
    }
}
