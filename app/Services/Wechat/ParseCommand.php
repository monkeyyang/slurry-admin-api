<?php

namespace app\common\WechatMsg;

use app\common\service\StringParser;
use app\common\WechatMsg\Commands\BillCommand;
use app\common\WechatMsg\Commands\CodeCommand;
use app\common\WechatMsg\Commands\OpenCommand;

class ParseCommand
{

    /**
     * 解析命令
     *
     * @param array $input
     * @return void
     */
    public function __construct(array $input)
    {
        $handler = MessageHandlerFactory::createHandler($input['type']);
        if ($handler !== null) {
            try {
                $handler->handle($input);
            } catch (\Exception $e) {

            }
        } else {
            // 没有匹配到对应的处理器，进行默认处理或报错
        }

    }
}