<?php

namespace App\Services\Wechat\Commands;

interface CommandStrategy {
    public function execute(array $input);
}
