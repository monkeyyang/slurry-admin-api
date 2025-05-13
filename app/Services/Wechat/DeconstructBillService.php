<?php

namespace App\Services\Wechat;

class DeconstructBillService
{
    private array $billData = [];
    private string $msg;
    // 国家
    private array $countries = [
        'US'  => '美国',
        'PT'  => '葡萄牙',
        'LU'  => '卢森堡',
        'GR'  => '希腊',
        'UAE' => '阿联酋',
        'RUS' => '俄罗斯',
        'PH'  => '菲律宾',
        'DNK' => '丹麦',
        'SA'  => '沙特',
        'WU'  => '南非',
        'PL'  => '波兰',
        'MX'  => '墨西哥',
        'IDN' => '印尼',
        'TUR' => '土耳其',
        'NOR' => '挪威',
        'BRA' => '巴西',
        'IND' => '印度',
        'MAS' => '马来',
        'HK'  => '香港',
        'JP'  => '日本',
        'TW'  => '台湾',
        'SE'  => '瑞典',
        'UK'  => '英国',
        'DE'  => '德国',
        'CHF' => '瑞士',
        'FI'  => '芬兰',
        'FR'  => '法国',
        'NL'  => '荷兰',
        'AUD' => '澳大利亚',
        'CAD' => '加拿大',
        'SG'  => '新加坡',
        '比'  => '比利时',
        'AT'  => '奥地利',
        'ES'  => '西班牙',
        'IT'  => '意大利',
        'IRL' => '爱尔兰'
    ];

    private array $card = [
        '美国'     => 'US',
        '美'       => 'US',
        'us'       => 'US',
        'usd'      => 'US',
        'usa'      => 'US',
        '葡萄牙'   => 'PT',
        '葡'       => 'PT',
        '卢森堡'   => 'LU',
        '卢'       => 'LU',
        '希腊'     => 'GR',
        '希'       => 'GR',
        '阿联酋'   => 'UAE',
        '阿'       => 'UAE',
        '俄罗斯'   => 'RUS',
        '俄'       => 'RUS',
        'rus'      => 'RUS',
        'ru'       => 'RUS',
        '菲律宾'   => 'PH',
        '菲'       => 'PH',
        '丹麦'     => 'DNK',
        '丹'       => 'DNK',
        '沙特'     => 'SA',
        '沙'       => 'SA',
        '南非'     => 'WU',
        '南'       => 'WU',
        '波兰'     => 'PL',
        '波'       => 'PL',
        '墨西哥'   => 'MX',
        '墨'       => 'MX',
        '印尼'     => 'IDN',
        '无'       => 'IDN',
        '土耳其'   => 'TUR',
        '土'       => 'TUR',
        'tur'      => 'TUR',
        'tu'       => 'TUR',
        '挪威'     => 'NOR',
        '挪'       => 'NOR',
        '巴西'     => 'BRA',
        '巴'       => 'BRA',
        '印度'     => 'IND',
        'in'       => 'IND',
        '马来'     => 'MAS',
        '马'       => 'MAS',
        '香港'     => 'HK',
        '香'       => 'HK',
        '日本'     => 'JP',
        '日'       => 'JP',
        '台湾'     => 'TW',
        '台'       => 'TW',
        'twn'      => 'TW',
        '瑞典'     => 'SE',
        '英国'     => 'UK',
        '英'       => 'UK',
        'uk'       => 'UK',
        '德国'     => 'DE',
        '德'       => 'DE',
        '瑞士'     => 'CHF',
        '芬兰'     => 'FI',
        '芬'       => 'FI',
        '法国'     => 'FR',
        '法'       => 'FR',
        '荷兰'     => 'NL',
        '荷'       => 'NL',
        '澳大利亚' => 'AUD',
        'aud'      => 'AUD',
        'au'       => 'AUD',
        '新'       => 'NZD',
        '新加坡'   => 'SG',
        '比利时'   => '比',
        '比'       => '比',
        '奥地利'   => 'AT',
        '西班牙'   => 'ES',
        '西'       => 'ES',
        '意大利'   => 'IT',
        '意'       => 'IT',
        '爱尔兰'   => 'IRL',
        '爱'       => 'IRL',
        'irl'      => 'IRL',
        '澳洲'     => 'AUD',
        '澳'       => 'AUD',
        '加拿大'   => 'CAD',
        '加'       => 'CAD',
        'ca'       => 'CAD',
        'cad'      => 'CAD',
        'jp'       => 'JP',
        'bra'      => 'BRA',
        '新西兰'   => 'NZD',
        'nzd'      => 'NZD',
        'nz'       => 'NZD',
        '欧盟'     => 'EUR',
        '欧'       => 'EUR',
        'eur'      => 'EUR',
        'eu'       => 'EUR',
        '韩国'     => 'KR',
        '韩'       => 'KR',
        'kr'       => 'KR',
        'mas'      => 'MAS',
        'ma'       => 'MAS',
        'mx'       => 'MX',
        'hk'       => 'HK',
        'pl'       => 'PL',
        'de'       => 'DE'
    ];
    // 卡密类型
    private array $codeTypes = [
        'itunes' => 'it',
        '苹果' => 'it',
        'google' => 'google',
        '谷歌' => 'google',
        // 雷蛇
        '雷蛇' => '雷蛇',

        // 丝芙兰
        '丝芙兰' => '丝芙兰',

        // 鞋柜/foot
        '鞋柜' => 'foot',
        'foot' => 'foot',

        // Xbox
        'xbox' => 'xbox',
        'xb' => 'xbox',

        // 香草
        '香草' => '香草',

        // tt
        'tt' => 'tt',

        // Steam/蒸汽
        '蒸汽' => 'steam',
        'steam' => 'steam',

        // Amazon/亚马逊
        'amazon' => 'amazon',
        '亚马逊' => 'amazon',

        // nd
        'nd' => 'nd',

        // eBay/易趣
        '易趣' => 'ebay',
        'ebay' => 'ebay',

        // Roblox
        'ro' => 'roblox',
        'roblox' => 'roblox',

        // 其他
        'qt' => 'qt',
    ];

    public function __construct(string $msg)
    {
        $this->msg = $msg;
    }

    /**
     * 前置校验，账单必须是+或-开头的字符串（去除前后空格后的字符串）
     * @return bool
     */
    private function validatePreConditions(): bool
    {
        if ($this->msg[1] != '+' && $this->msg[1] != '-') return false;
        return true;
    }

    /**
     * 预处理消息内容
     *
     * @return array
     */
    private function processMessageItems(): array
    {
        // 将连续的多个换行替换成一个换行
        $processedMessages = preg_replace("/\n+/", "\n", $this->msg);
        // 将消息根据\n+或\n-解析消息数组
        $pattern  = "/(\n\+|\n\-)/";
        $segments = preg_split($pattern, $processedMessages, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (empty($segments)) return [];

        $result[] = $segments[0]; // 确定是+还是-
        // 输出切割后的字符串
        for ($i = 1; $i < count($segments); $i += 2) {
            $symbol   = trim($segments[$i]);
            $content  = trim($segments[$i + 1]);
            $result[] = $symbol . $content;
        }

        return $result;
    }

    /**
     * 单条解析规则
     *
     * @param $item
     * @return array
     */
    private function rules($item): array
    {
        $result = [];
        // 识别金额和汇率部分，添加#符号
        $pattern = '/([+-]?\d+(?:\.\d+)?(?:[*\/]\d+(?:\.\d+)?)?)(\*|\/)?([^\d.*\/#])?/';
        // 匹配并替换
        $messageWithHash = preg_replace($pattern, '$1$2#$3', $item['content'], 1);
        // 连续多个#替换城1个
        $pattern         = '/#+/';
        $replacement     = '#';
        $messageWithHash = preg_replace($pattern, $replacement, $messageWithHash);
        // 将消息中的回车统一转换成#
        $param = str_replace("\n", "#", $messageWithHash);
        // 按照#切割消息
        $parts = explode('#', $param);
        if (empty($parts)) return $result;
        // 获取金额字符串
        $moneyAndRatePart = array_shift($parts);
        // 识别乘号或除号
        $operator = '';
        if (preg_match('/([*\/])/', $moneyAndRatePart, $matches)) {
            $operator = $matches[1];
        }
        // 未识别的运算符直接返回
        if (empty($operator)) return $result;
        $amountAndRateParsed = explode($operator, $moneyAndRatePart);
        if (empty($amountAndRateParsed)) return $result;
        $money = $this->validateAmount($amountAndRateParsed[0]);
        $rate  = $this->validateExchangeRate($amountAndRateParsed[1]);
        if(!$money || !$rate) return $result;
        // 计算总额
        if ($operator == '/') {
            $result['type']  = 2;
            $result['amount'] = bcdiv($amountAndRateParsed[0], $amountAndRateParsed[1], 2);
        } else {
            $result['type']  = 1;
            $result['amount'] = bcmul($amountAndRateParsed[0], $amountAndRateParsed[1], 2);
        }
        // 遍历其他部分，解构数据
        for ($i = 0; $i < count($parts); $i++) {
            $parts[$i] = str_replace('&nbsp;', ' ', htmlentities(trim($parts[$i])));
            if (empty($parts[$i])) continue;
            // 解析国家
            $result['country'] = $this->parseCountry($parts[$i]);
            if(!empty($result['country'])) continue;
            // 解析卡种
            $result['cardType'] = $this->parseCard($parts[$i]);
            if(!empty($result['cardType'])) continue;
            // 是否是结算标识
            if (trim($parts[$i]) == '结算') {
                $result['isSettle']      = 1;
                continue;
            }
            // 是否为补单标识
            if(trim($parts[$i]) == '补单') {
                $result['isForceBilled']      = 1;
                continue;
            }
            // 是否备注
            if($this->isRemark($parts[$i])) {
                $result['remark'] = $parts[$i];
                continue;
            }
            // 解析卡密
            $code = $this->parseCode($parts[$i]);
            if(!$code)  {
                $result['codes'][] = $parts[$i];
                continue;
            }
            // 其他部分为备注
            $result['remark'] = $parts[$i];
        }

        return $result;
    }

    /**
     * 是否有中文
     *
     * @param $item
     * @return bool|int
     */
    private function isRemark($item): bool|int
    {
        return preg_match('/[\x{4e00}-\x{9fa5}]+/u', trim($item));
    }

    /**
     * 解析卡种
     *
     * @param $item
     * @return mixed|string
     */
    private function parseCard($item): mixed
    {
        $item = strtolower(trim($item));
        return $this->codeTypes[$item];
    }

    /**
     * 解析卡密
     * 卡密规则：
     * - 苹果卡密为X开头的16位字母和数字组成的字符串
     * - 苹果ID也可能是邮箱格式
     * - 其他卡密为连续的字符串或数字组合
     * - 或者可以是空格或-连接的字符串（每部分不少于4个字符）
     * - 总长度最短不小于10位
     * - 字母不区分大小写
     *
     * @param string $item 待解析的卡密字符串
     * @return string 解析出的有效卡密
     */
    private function parseCode(string $item): string
    {
        // 先检查是否为苹果ID邮箱格式
        $appleIdResult = $this->isAppleId($item);
        if (!empty($appleIdResult)) {
            return $appleIdResult;
        }

        // 检查是否为苹果卡密（X开头的16位字母数字）
        $appleCodeResult = $this->isAppleCode($item);
        if (!empty($appleCodeResult)) {
            return $appleCodeResult;
        }

        // 检查其他类型的卡密
        return $this->parseGeneralCode($item);
    }

    /**
     * 解析通用卡密
     * @param string $code 原始卡密
     * @return string 解析后的大写卡密（若无有效卡密则返回空字符串）
     */
    private function parseGeneralCode(string $code): string
    {
        $msg = trim($code);

        // 1. 先检查是否是分隔符格式（空格或-）
        if (preg_match('/[- ]/', $msg)) {
            $parts = preg_split('/[- ]+/', $msg);
            $validParts = array_filter($parts, fn($p) => strlen($p) >= 4);

            if (count($validParts) >= 2) {
                $combined = implode('', $validParts);
                if (strlen($combined) >= 10) {
                    return strtoupper($code); // 直接返回原始卡密
                }
            }
        }

        // 2. 如果不是分隔符格式，再检查连续字符串格式
        $cleanCode = preg_replace('/[^A-Z0-9]/i', '', $msg);
        if (strlen($cleanCode) >= 10) {
            return strtoupper($code); // 直接返回原始卡密
        }

        // 3. 都不符合条件，返回空字符串
        return '';
    }

    /**
     * 是否为苹果卡密（X开头的16位字母数字）
     */
    private function isAppleCode(string $code): string
    {
        $msg = strtoupper(preg_replace('/\s+/', '', trim($code)));

        // 苹果卡密正则：X开头，16位字母数字
        return preg_match('/^X[A-Z0-9]{15}$/', $msg) ? strtolower($msg) : '';
    }

    /**
     * 解析国家
     *
     * @param string $item
     * @return mixed|string
     */
    private function parseCountry(string $item): mixed
    {
        $countryCode   = strtolower(trim($item));
        return !empty($this->card[$countryCode]) ? $this->card[$countryCode] : '';
    }

    /**
     * 是否为苹果ID，邮箱格式字符串
     *
     * @param $code
     * @return string
     */
    private function isAppleId($code): string
    {
        // 去除所有空格，并转换成大写
        $msg = strtoupper(preg_replace('/\s+/', '', trim($code)));
        $pattern = "/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,8}$/";

        // 只匹配第一个符合的邮箱
        if (preg_match($pattern, $msg, $match)) {
            return strtolower($match[0]); // 返回第一个匹配的邮箱（转为小写）
        }

        return ''; // 没有匹配时返回空字符串
    }

    /**
     *
     * @param $amount
     * @return bool
     */
    private function validateAmount($amount): bool
    {
        return is_int($amount) || is_float($amount);
    }

    /**
     * @param $rate
     * @return bool
     */
    private function validateExchangeRate($rate): bool {
        return is_int($rate) || is_float($rate);
    }

    /**
     * 解构数据
     *
     * @return array
     */
    public function deconstruct(): array
    {
        if(!$this->validatePreConditions()) return [];
        $items = $this->processMessageItems();
        if(empty($items)) return [];

        foreach ($items as $item) {
            $this->billData[] = $this->rules($item);
        }

        return $this->billData;
    }
}
