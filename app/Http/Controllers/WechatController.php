<?php

namespace App\Http\Controllers;

use App\Services\Wechat\ParseCommand;
use App\Services\Wechat\WechatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WechatController extends Controller
{
    public function index(Request $request): string
    {
        $input = $request->getContent();
        $json = json_decode($input, true);
        new ParseCommand($json);
        return 'success';
    }

//    public function test(Request $request): string
//    {
//        $roomId = $request->get('room_id');
//        $content = $request->get('content');
//        WechatService::sendWechatBotChatRoomList();
//        return 'success';
//    }
}
