<?php

namespace App\Services\Wechat;

class ParseCommand
{

    /**
     * 解析命令
     *
     * @param $input
     * @return void
     */
    public function __construct($input)
    {
        $handler = MessageHandlerFactory::createHandler($input['type']);
        if ($handler !== null) {
            try {
                $handler->handle($input);
            } catch (\Exception $e) {

            }
        }
    }
}
