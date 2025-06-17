<?php

namespace App\Exceptions;

use Exception;

class GiftCardExchangeException extends Exception
{
    // 异常代码常量
    const CODE_RETRY_EXCEEDED = -1;           // 重试次数过多
    const CODE_NO_SESSION = -2;              // 无会话数据
    const CODE_REQUEST_FAILED = -3;          // 创建网络请求失败
    const CODE_PROXY_PARSE_ERROR = -100;     // 代理解析错误
    const CODE_PROXY_CONNECT_FAILED = -5;    // 代理连接失败
    const CODE_RESPONSE_TIMEOUT = -30;       // 响应超时
    const CODE_TOO_MANY_REQUESTS = -6;       // 服务器请求过多
    const CODE_NEED_RELOGIN = -7;            // 需要重新登录
    const CODE_NETWORK_REQUEST_FAILED = -8;  // 网络请求失败
    const CODE_READ_RESPONSE_FAILED = -9;    // 读取响应失败
    const CODE_JSON_PARSE_FAILED = -10;      // 解析JSON数据失败
    const CODE_COUNTRY_MISMATCH = -11;       // 国家不匹配
    const CODE_CARD_ALREADY_REDEEMED = -12;  // 礼品卡已兑换
    const CODE_CARD_NOT_EXISTS = -13;        // 礼品卡不存在
    const CODE_OTHER_ERROR = -14;            // 其他错误
    const CODE_APPLE_EMPTY_DATA = -30;       // 苹果返回空数据
    const CODE_ABNORMAL_DATA = -20;          // 异常数据

    protected int $errorCode;
    protected array $context;

    public function __construct(int $errorCode, string $message = '', array $context = [], Exception $previous = null)
    {
        $this->errorCode = $errorCode;
        $this->context = $context;
        
        if (empty($message)) {
            $message = $this->getDefaultMessage($errorCode, $context);
        }
        
        parent::__construct($message, $errorCode, $previous);
    }

    /**
     * 获取默认错误消息
     */
    protected function getDefaultMessage(int $errorCode, array $context): string
    {
        $messages = [
            self::CODE_RETRY_EXCEEDED => '重试次数过多:' . ($context['retry'] ?? '未知') . '次',
            self::CODE_NO_SESSION => '无此用户的会话数据，请先登录',
            self::CODE_REQUEST_FAILED => '创建兑换网络请求失败',
            self::CODE_PROXY_PARSE_ERROR => $context['error'] ?? '代理解析错误',
            self::CODE_PROXY_CONNECT_FAILED => '代理连接失败',
            self::CODE_RESPONSE_TIMEOUT => '响应超时,请刷新登录确认是否充值成功',
            self::CODE_TOO_MANY_REQUESTS => '服务器请求过多，请稍后重试:' . ($context['status'] ?? ''),
            self::CODE_NEED_RELOGIN => '需要重新登录:' . ($context['status'] ?? ''),
            self::CODE_NETWORK_REQUEST_FAILED => '兑换网络请求失败:' . ($context['status'] ?? ''),
            self::CODE_READ_RESPONSE_FAILED => '读取兑换请求响应失败:' . ($context['error'] ?? ''),
            self::CODE_JSON_PARSE_FAILED => '解析兑换返回的json数据失败:' . ($context['error'] ?? ''),
            self::CODE_COUNTRY_MISMATCH => '兑换失败: 国家不匹配',
            self::CODE_CARD_ALREADY_REDEEMED => '兑换失败:' . ($context['msg'] ?? '礼品卡已被兑换'),
            self::CODE_CARD_NOT_EXISTS => '兑换失败:' . ($context['msg'] ?? '礼品卡不存在'),
            self::CODE_OTHER_ERROR => '兑换失败:' . ($context['msg'] ?? '未知错误'),
            self::CODE_APPLE_EMPTY_DATA => '苹果返回空数据,请刷新登录确认是否充值成功,状态码:' . ($context['status'] ?? ''),
            self::CODE_ABNORMAL_DATA => '异常数据[' . ($context['status_code'] ?? '') . '][' . ($context['content_type'] ?? '') . '][' . ($context['body'] ?? '') . ']'
        ];

        return $messages[$errorCode] ?? '未知错误';
    }

    /**
     * 获取错误代码
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 获取上下文信息
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 静态创建方法
     */
    public static function create(int $errorCode, array $context = [], Exception $previous = null): self
    {
        return new self($errorCode, '', $context, $previous);
    }

    /**
     * 判断是否为业务逻辑错误（不需要记录堆栈跟踪）
     */
    public function isBusinessError(): bool
    {
        $businessErrorCodes = [
            self::CODE_CARD_ALREADY_REDEEMED,
            self::CODE_CARD_NOT_EXISTS,
            self::CODE_COUNTRY_MISMATCH,
            self::CODE_NO_SESSION,
            self::CODE_NEED_RELOGIN,
        ];

        return in_array($this->errorCode, $businessErrorCodes);
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    public function isSystemError(): bool
    {
        return !$this->isBusinessError();
    }
} 