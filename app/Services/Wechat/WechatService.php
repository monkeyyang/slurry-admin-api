<?php

namespace App\Services\Wechat;

use App\Models\WechatBotUsers;
use App\Models\WechatRoomLogs;
use App\Models\WechatRooms;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WechatService
{

    private string $roomId; // 当前操作微信群组
    private string $wxid; // 当前操作微信ID
    private string $msgId; // 微信消息ID
    private string $msg;
    private int $wechatBotClientId; // 当前提交的机器人终端标识
    private bool $isSentCode = true; // 是否忽略群内须已发送卡密
    private bool $isForceBilled = false; // 是否要求强制加账

    private WechatBotUsers $botUser; // 当前操作机器人用户
    private WechatRooms $wechatRoom;

    /**
     * 构造
     *
     * @param array $input
     */
    public function __construct(array $input)
    {
        $this->roomId   = $input['data']['room_wxid'];
        $this->wxid     = $input['data']['from_wxid'];
        $this->msgId    = $input['data']['msgid'];
        $this->msg      = str_replace('&nbsp;', ' ', htmlentities(trim($input['data']['msg'])));
        $this->wechatRoom = WechatRooms::getByRoomId($this->roomId);
        $this->botUser = WechatBotUsers::getUserByWxid($this->wxid);
        // 非出卡和收卡群，自动忽略验证群聊中是否发送卡密
        if($this->wechatRoom->type != WechatRooms::ROOM_TYPE_IN || $this->wechatRoom->type != WechatRooms::ROOM_TYPE_OUT){
            $this->isSentCode = false;
        }
    }

    /**
     * 是否是微信机器人用户
     *
     * @return boolean
     */
    public function isWechatBotUser(): bool
    {
        return $this->botUser?? false;
    }

    /**
     * 群聊机器人是否开启
     *
     * @return bool
     */
    public function roomIsOpen(): bool
    {
        return !empty($this->wechatRoom) ?? false;
    }


    private function buildBillData(): array
    {
        // 处理消息内容
        $deconstructService = new DeconstructBillService($this->msg);
        return $deconstructService->deconstruct();
    }

    /**
     * 记录错误信息到日志
     *
     * @param string $errorMessage 错误消息
     * @param array $context 错误上下文
     * @param string $channel 日志通道，默认为wechat
     * @return void
     */
    public function logError(string $errorMessage, array $context = [], string $channel = 'wechat'): void
    {
        // 提取关键信息，避免日志过于冗长
        $logContext = [
            'client_id' => $context['client_id'] ?? 'unknown',
            'room_id' => $context['data']['room_wxid'] ?? $this->roomId ?? 'unknown',
            'from_id' => $context['data']['from_wxid'] ?? $this->wxid ?? 'unknown',
            'msg_id' => $context['data']['msgid'] ?? $this->msgId ?? 'unknown',
            'message' => isset($context['data']['msg']) ? 
                         substr($context['data']['msg'], 0, 100) . 
                         (strlen($context['data']['msg']) > 100 ? '...' : '') : 
                         'no_message'
        ];

        // 使用Laravel日志记录错误
        Log::channel($channel)->error($errorMessage, $logContext);

        // 记录详细堆栈跟踪以便调试严重问题
        if (strpos($errorMessage, 'Exception') !== false || strpos($errorMessage, 'Error') !== false) {
            Log::channel($channel)->error(
                "详细错误: " . $errorMessage . "\n堆栈跟踪: " . (new \Exception())->getTraceAsString(), 
                $logContext
            );
        }
    }

    /**
     * 账单处理
     *
     * @return mixed 处理结果
     */
    public function billed(): mixed
    {
        try {
            // 解析账单数据
            $billData = $this->buildBillData();
            if (empty($billData)) {
                return false;
            }

            // 记录日志
            Log::channel('wechat')->info('处理账单数据', [
                'room_id' => $this->roomId,
                'wxid' => $this->wxid,
                'bill_data' => $billData
            ]);

            // 检查是否强制加账
            if ($this->isForceBilled) {
                // 无需验证直接写入加账队列
                return $this->addToBalanceQueue($billData);
            }

            // 检查是否需要验证群聊中的卡密记录
            if (!$this->isSentCode) {
                // 不需要验证当前群聊中是否有过当前卡密记录
                $passCheck = true;
            } else {
                // 验证当前群聊中是否有过当前卡密记录
                $passCheck = $this->verifyCardCodeInRoom($billData);
            }

            // 验证失败直接返回
            if (!$passCheck) {
                $this->sendWechatMsg('未在当前群聊中找到相关卡密记录，请先发送卡密');
                return false;
            }

            // 验证卡密是否重复加账
            if ($this->isCardAlreadyBilled($billData)) {
                $this->sendWechatMsg('该卡密已经加过账，不能重复加账');
                return false;
            }

            // 验证卡密是否有效
            if (!$this->isCardValid($billData)) {
                $this->sendWechatMsg('卡密无效或已过期');
                return false;
            }

            // 写入加账队列
            $result = $this->addToBalanceQueue($billData);
            
            // 记录消息处理日志
            $this->logMessageHandled();
            
            // 返回处理结果
            return $result ? $result : '加账成功';
            
        } catch (\Exception $e) {
            $this->logError('账单处理异常: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证群聊中是否有过卡密记录
     *
     * @param array $billData 账单数据
     * @return bool 验证结果
     */
    private function verifyCardCodeInRoom(array $billData): bool
    {
        // 如果群组类型不是出卡/收卡群，直接通过验证
        if ($this->wechatRoom->room_type != WechatRooms::ROOM_TYPE_OUT && 
            $this->wechatRoom->room_type != WechatRooms::ROOM_TYPE_IN) {
            return true;
        }

        // 从账单数据中提取卡密信息
        $cardCode = $billData['code'] ?? '';
        if (empty($cardCode)) {
            return false;
        }

        // 查询群聊消息记录中是否包含该卡密
        $hasRecord = WechatRoomLogs::where('room_id', $this->roomId)
                                  ->where('message', 'like', '%' . $cardCode . '%')
                                  ->where('created_at', '>', now()->subDays(1)) // 只查过去24小时内的消息
                                  ->exists();

        return $hasRecord;
    }

    /**
     * 检查卡密是否已经加过账
     *
     * @param array $billData 账单数据
     * @return bool 是否已加账
     */
    private function isCardAlreadyBilled(array $billData): bool
    {
        // 从账单数据中提取卡密信息
        $cardCode = $billData['code'] ?? '';
        if (empty($cardCode)) {
            return false;
        }

        // 查询数据库判断卡密是否已经加过账
        // 假设有一个卡密加账记录表 wechat_bill_records
        return DB::table('wechat_bill_records')
                ->where('card_code', $cardCode)
                ->where('status', 1) // 已处理状态
                ->exists();
    }

    /**
     * 验证卡密是否有效
     *
     * @param array $billData 账单数据
     * @return bool 卡密是否有效
     */
    private function isCardValid(array $billData): bool
    {
        // 从账单数据中提取卡密和类型
        $cardCode = $billData['code'] ?? '';
        $cardType = $billData['cardType'] ?? '';
        
        if (empty($cardCode) || empty($cardType)) {
            return false;
        }

        // 可以通过调用外部API或检查内部数据库来验证卡密
        // 这里仅作示例，实际实现取决于你的业务需求
        
        // 简单验证逻辑 - 可以替换为实际的API调用
        $validFormat = false;
        
        // 根据不同的卡类型验证格式
        switch ($cardType) {
            case 'it': // 苹果卡
                $validFormat = preg_match('/^X[A-Z0-9]{15}$/i', $cardCode);
                break;
            case 'google': // 谷歌卡
                $validFormat = strlen($cardCode) >= 10;
                break;
            default:
                // 其他卡类型的验证逻辑
                $validFormat = strlen($cardCode) >= 8;
        }
        
        return $validFormat;
    }

    /**
     * 添加到加账队列
     *
     * @param array $billData 账单数据
     * @return bool 添加结果
     */
    private function addToBalanceQueue(array $billData): bool
    {
        try {
            // 记录加账信息到数据库
            $billId = DB::table('wechat_bill_records')->insertGetId([
                'room_id' => $this->roomId,
                'wxid' => $this->wxid,
                'card_code' => $billData['code'] ?? '',
                'card_type' => $billData['cardType'] ?? '',
                'amount' => $billData['amount'] ?? 0,
                'country' => $billData['country'] ?? '',
                'remark' => $billData['remark'] ?? '',
                'status' => 0, // 待处理
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 可以选择将任务推送到队列进行异步处理
            // ProcessBillJob::dispatch($billId);
            
            // 返回成功信息给用户
            $this->sendWechatMsg("加账请求已受理，卡密: " . ($billData['code'] ?? 'N/A') . 
                " 金额: " . ($billData['amount'] ?? 0));

            return true;
        } catch (\Exception $e) {
            $this->logError('添加到加账队列失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 记录消息已处理
     *
     * @return void
     */
    private function logMessageHandled(): void
    {
        try {
            // 记录已处理的消息ID，防止重复处理
            WechatRoomLogs::create([
                'msgid' => $this->msgId,
                'room_id' => $this->roomId,
                'from_wxid' => $this->wxid,
                'message' => $this->msg,
                'handled' => 1,
                'created_at' => now()
            ]);
        } catch (\Exception $e) {
            $this->logError('记录消息处理状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证前置条件
     *
     * @return bool 验证通过返回true，否则返回false
     */
    public function validatePreConditions(): bool
    {
        // 检查当前群聊是否开启机器人
        if(!$this->roomIsOpen()) return false;

        // 检查消息是否已经处理过
        if ($this->isMessageAlreadyHandled()) {
            return false;
        }

        return true;
    }

    /**
     * 检查消息是否已经处理过
     *
     * @return bool 消息已处理返回true，否则返回false
     */
    public function isMessageAlreadyHandled(): bool
    {
        $record = WechatRoomLogs::where('msgid', $this->msgId)->first();
        return !empty($record);
    }

    /**
     * 设置忽略群内须先发卡验证
     *
     * @return bool
     */
    public function setIgnoreSentCode(): bool
    {
        $this->isSentCode = true;
        return true;
    }

    /**
     * 强制加账忽略所有验证
     *
     * @return bool
     */
    public function setForceBilled(): bool
    {
        $this->isForceBilled = true;
        return true;
    }


    /**
     * 开启当前群聊微信机器人功能
     *
     * @return bool
     */
    public function openWechatBot(): bool
    {
        if($this->isWechatBotUser()) return false;
        try {
            $this->wechatRoom::createOrUpdate($this->roomId, ['status' => WechatRooms::OPEN_STATUS]);
            // 发送获取群组消息以更新群组信息
            self::sendWechatBotChatRoomMembersMessage($this->roomId);
        } catch (\Exception $exception) {
            return false;
        }

        $this->sendWechatMsg('当前群聊微信机器人启动成功');
        return true;
    }

    /**
     * 关闭当前群组机器人功能
     *
     * @return bool
     */
    public function closeWechatBot(): bool
    {
        if($this->isWechatBotUser()) return false;
        try {
            $this->wechatRoom->closeBot();
        }catch (\Exception $exception){
            return false;
        }
        $this->sendWechatMsg('当前群聊微信机器人关闭成功');
        return true;
    }

    /**
     * 发送获取群聊列表消息
     *
     * @return void
     */
    public static function sendWechatBotChatRoomList(): void
    {
        // 拼接内容
        $content = [
            'data'      => [
                'detail' => 1,
            ],
            'client_id' => 1,
            'type'      => 'MT_DATA_CHATROOMS_MSG'
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'http://43.140.224.234:6666/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($content),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * 发送获取群聊成员信息列表
     *
     * @param string $roomId
     * @return void
     */
    public static function sendWechatBotChatRoomMembersMessage(string $roomId): void
    {
        // 拼接内容
        $content = [
            'data'      => [
                'room_wxid' => $roomId,
            ],
            'client_id' => 1,
            'type'      => 'MT_DATA_CHATROOM_MEMBERS_MSG'
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'http://43.140.224.234:6666/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($content),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * 发送文本消息到群聊
     *
     * @param $content
     * @return void
     */
    public function sendWechatMsg($content): void
    {
        if (!is_array($content)) {
            // 拼接内容
            $content = [
                'data'      => [
                    'to_wxid' => $this->roomId,
                    'content' => $content
                ],
                'client_id' => 1,
                'type'      => 'MT_SEND_TEXTMSG'
            ];
        }
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'http://43.140.224.234:6666/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($content),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);

    }
}
