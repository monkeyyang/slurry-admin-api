<?php

namespace app\common\WechatMsg;

use app\common\WechatMsg\MessageHandler\ChatroomsMembersMessageHandler;
use app\common\WechatMsg\MessageHandler\ChatRoomsMessageHandler;
use app\common\WechatMsg\MessageHandler\ChatroomsNoMembersMessageHandler;
use app\common\WechatMsg\MessageHandler\ImageMessageHandler;
use app\common\WechatMsg\MessageHandler\PingMessageHandler;
use app\common\WechatMsg\MessageHandler\RecvOtherAppMessageHandler;
use app\common\WechatMsg\MessageHandler\RecvSystemMessageHandler;
use app\common\WechatMsg\MessageHandler\RoomCreateNotifyMessageHandler;
use app\common\WechatMsg\MessageHandler\TalkerChangeMessageHandler;
use app\common\WechatMsg\MessageHandler\TextMessageHandler;
use app\common\WechatMsg\MessageHandler\UserLoginMessageHandler;
use app\common\WechatMsg\MessageHandler\WechatLoginHandler;
use app\common\WechatMsg\MessageHandler\WechatLogoutHandler;

class MessageHandlerFactory
{
    public static function createHandler($messageType)
    {
        switch ($messageType) {
            case 'MT_RECV_TEXT_MSG': // 文本消息处理
                return new TextMessageHandler();
            case 'MT_SEND_IMGMSG': // 图片消息处理
                return new ImageMessageHandler();
            case 'MT_TALKER_CHANGE_MSG':// 更改对话群组消息处理
                return new TalkerChangeMessageHandler();
            case 'MT_RECV_SYSTEM_MSG':// 系统消息处理(更改群名等)
                return new RecvSystemMessageHandler();
            case 'MT_DATA_CHATROOMS_NO_MEMBER_MSG': // 获取群信息（无成员）
                return new ChatroomsNoMembersMessageHandler();
            case 'MT_DATA_CHATROOM_MEMBERS_MSG': // 获取群信息（有成员）
                return new ChatroomsMembersMessageHandler();
            case 'MT_DATA_CHATROOMS_MSG': // 获取群组列表
                return new ChatRoomsMessageHandler();
            case 'MT_ROOM_CREATE_NOTIFY_MSG': // 群创建消息处理
                return new RoomCreateNotifyMessageHandler();
            case 'MT_USER_LOGIN': // 登录微信消息处理
                return new UserLoginMessageHandler();
            case 'MT_RECV_OTHER_APP_MSG':// 引入消息
                return new RecvOtherAppMessageHandler();
            case 'MT_ENTER_WECHAT':// 进入微信
                return new WechatLoginHandler();
            case 'MT_QUIT_LOGIN_MSG': // 注销登录
                return new WechatLogoutHandler();
            case 'MT_QUIT_WECHAT_MSG':// 退出微信程序
                return new WechatLogoutHandler();
            case 'MT_DATA_OWNER_MSG':
                return new WechatLoginHandler();
            case 'PING': // 机器人心跳检测
                return new PingMessageHandler();
            // 添加更多消息类型的处理器...
            default:
                return null; // 返回一个默认处理器或抛出异常
        }
    }
}