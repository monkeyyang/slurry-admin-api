<?php

namespace App\Services\Wechat;

use App\Services\Wechat\MessageHandler\ChatroomsMembersMessageHandler;
use App\Services\Wechat\MessageHandler\ChatRoomsMessageHandler;
use App\Services\Wechat\MessageHandler\ChatroomsNoMembersMessageHandler;
use App\Services\Wechat\MessageHandler\ImageMessageHandler;
use App\Services\Wechat\MessageHandler\PingMessageHandler;
use App\Services\Wechat\MessageHandler\RecvOtherAppMessageHandler;
use App\Services\Wechat\MessageHandler\RecvSystemMessageHandler;
use App\Services\Wechat\MessageHandler\RoomCreateNotifyMessageHandler;
use App\Services\Wechat\MessageHandler\TalkerChangeMessageHandler;
use App\Services\Wechat\MessageHandler\TextMessageHandler;
use App\Services\Wechat\MessageHandler\UserLoginMessageHandler;
use App\Services\Wechat\MessageHandler\WechatLoginHandler;
use App\Services\Wechat\MessageHandler\WechatLogoutHandler;

class MessageHandlerFactory
{
    public static function createHandler($messageType): ImageMessageHandler|RoomCreateNotifyMessageHandler|UserLoginMessageHandler|TextMessageHandler|WechatLoginHandler|ChatRoomsMessageHandler|RecvOtherAppMessageHandler|RecvSystemMessageHandler|PingMessageHandler|ChatroomsMembersMessageHandler|ChatroomsNoMembersMessageHandler|WechatLogoutHandler|TalkerChangeMessageHandler|null
    {

        /**
         * 匹配对应消息类型
         *
         * MT_RECV_TEXT_MSG 文本消息处理
         * MT_SEND_IMGMSG 图片处理
         * MT_TALKER_CHANGE_MSG 更改群组处理
         * MT_RECV_SYSTEM_MSG 系统消息处理
         *
         */
        return match ($messageType) {
            'MT_RECV_TEXT_MSG' => new TextMessageHandler(),
            'MT_SEND_IMGMSG' => new ImageMessageHandler(),
            'MT_TALKER_CHANGE_MSG' => new TalkerChangeMessageHandler(),
            'MT_RECV_SYSTEM_MSG' => new RecvSystemMessageHandler(),
            'MT_DATA_CHATROOMS_NO_MEMBER_MSG' => new ChatroomsNoMembersMessageHandler(),
            'MT_DATA_CHATROOM_MEMBERS_MSG' => new ChatroomsMembersMessageHandler(),
            'MT_DATA_CHATROOMS_MSG' => new ChatRoomsMessageHandler(),
            'MT_ROOM_CREATE_NOTIFY_MSG' => new RoomCreateNotifyMessageHandler(),
            'MT_USER_LOGIN' => new UserLoginMessageHandler(),
            'MT_RECV_OTHER_APP_MSG' => new RecvOtherAppMessageHandler(),
            'MT_ENTER_WECHAT', 'MT_DATA_OWNER_MSG' => new WechatLoginHandler(),
            'MT_QUIT_LOGIN_MSG', 'MT_QUIT_WECHAT_MSG' => new WechatLogoutHandler(),
            'PING' => new PingMessageHandler(),
            default => null,
        };
    }
}
