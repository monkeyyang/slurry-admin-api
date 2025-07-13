<?php

/**
 * 快速导出兑换成功记录脚本
 *
 * 专注于用户所需的关键字段：
 * - 兑换码、国家、金额、账号余款、账号、错误信息、执行状态、时间、群聊、计划、汇率
 *
 * 数据来源：iTunes交易账户日志表 (itunes_trade_account_logs)
 *
 * 用法：
 * php export_successful_exchanges.php
 * php export_successful_exchanges.php --limit=1000
 * php export_successful_exchanges.php --country=US --output=success_US.csv
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Models\MrRoom;
use App\Models\Countries;

// 初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class SuccessfulExchangesExporter
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'output'     => 'successful_exchanges.csv',
            'limit'      => null,
            'country'    => null,
            'start_date' => null,
            'end_date'   => null,
        ], $options);
    }

    /**
     * 导出成功兑换记录
     */
    public function export(): void
    {
        echo "开始导出兑换成功记录...\n";

        // 获取 iTunes 交易日志中的成功记录
        $itunesRecords = $this->getItunesSuccessRecords();

        if (empty($itunesRecords)) {
            echo "没有找到兑换成功的记录\n";
            return;
        }

        // 按时间排序
        usort($itunesRecords, function ($a, $b) {
            return strtotime($b['时间']) - strtotime($a['时间']);
        });

        echo "找到 " . count($itunesRecords) . " 条成功记录\n";

        // 导出到CSV
        $this->exportToCsv($itunesRecords);

        echo "导出完成！文件保存在: {$this->options['output']}\n";
    }

    /**
     * 获取iTunes交易日志中的成功记录
     */
    private function getItunesSuccessRecords(): array
    {
        $query = ItunesTradeAccountLog::query()
            ->with(['account', 'plan', 'rate'])
            ->where('status', 'success');

        // 应用过滤条件
        if ($this->options['country']) {
            $query->where('country_code', $this->options['country']);
        }

        if ($this->options['start_date']) {
            $query->where('exchange_time', '>=', $this->options['start_date']);
        }

        if ($this->options['end_date']) {
            $query->where('exchange_time', '<=', $this->options['end_date']);
        }

        $query->orderBy('exchange_time', 'desc');

        if ($this->options['limit']) {
            $query->limit($this->options['limit']);
        }

        $logs = $query->get();

        echo "从 iTunes 交易日志获取到 " . $logs->count() . " 条成功记录\n";

        return $logs->map(function ($log) {
            return $this->formatItunesRecord($log);
        })->toArray();
    }


    /**
     * 格式化iTunes记录
     */
    private function formatItunesRecord(ItunesTradeAccountLog $log): array
    {
        // 获取账号信息
        $account     = $log->account;
        $accountName = $account ? $account->account : '未知账号';

        // 获取计划信息
        $plan     = $log->plan;
        $planName = $plan ? $plan->name : '未知计划';

        // 获取汇率信息
        $rate      = $log->rate;
        $rateValue = $rate ? $rate->rate : '0';

        // 获取群聊信息
        $roomName = '';
        if ($log->room_id) {
            $roomInfo = MrRoom::where('room_id', $log->room_id)->first();
            if ($roomInfo) {
                $roomName = $roomInfo->room_name;
            }
        }

        // 获取国家信息
        $countryName = $log->country_code;
        $country     = Countries::where('code', $log->country_code)->first();
        if ($country) {
            $countryName = $country->name;
        }

        return [
            '兑换码'   => $log->code ?: '',
            '国家'     => $countryName,
            '金额'     => $log->amount ?: 0,
            '账号余款' => $log->after_amount ?: 0,
            '账号'     => $accountName,
            '错误信息' => $log->error_message ?: '',
            '执行状态' => '成功',
            '时间'     => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : '',
            '群聊'     => $roomName,
            '计划'     => $planName,
            '汇率'     => $rateValue,
            '数据来源' => 'iTunes交易日志',
        ];
    }


    /**
     * 导出到CSV文件
     */
    private function exportToCsv(array $records): void
    {
        $file = fopen($this->options['output'], 'w');

        // 添加UTF-8 BOM以确保Excel正确显示中文
        fwrite($file, "\xEF\xBB\xBF");

        // 写入标题行
        $headers = [
            '兑换码',
            '国家',
            '金额',
            '账号余款',
            '账号',
            '错误信息',
            '执行状态',
            '时间',
            '群聊',
            '计划',
            '汇率',
            '数据来源',
        ];

        fputcsv($file, $headers);

        // 写入数据行
        foreach ($records as $record) {
            $row = [
                $record['兑换码'],
                $record['国家'],
                $record['金额'],
                $record['账号余款'],
                $record['账号'],
                $record['错误信息'],
                $record['执行状态'],
                $record['时间'],
                $record['群聊'],
                $record['计划'],
                $record['汇率'],
                $record['数据来源'],
            ];

            fputcsv($file, $row);
        }

        fclose($file);
    }
}

// 解析命令行参数
function parseArgs(): array
{
    $options = [];
    $args    = $GLOBALS['argv'];

    for ($i = 1; $i < count($args); $i++) {
        if (strpos($args[$i], '--') === 0) {
            $param = substr($args[$i], 2);
            if (strpos($param, '=') !== false) {
                list($key, $value) = explode('=', $param, 2);
                $options[$key] = $value;
            } else {
                $options[$param] = true;
            }
        }
    }

    return $options;
}

// 显示帮助信息
function showHelp(): void
{
    echo "兑换成功记录导出工具\n\n";
    echo "用法:\n";
    echo "  php export_successful_exchanges.php [选项]\n\n";
    echo "选项:\n";
    echo "  --output=FILE              输出文件名 (默认: successful_exchanges.csv)\n";
    echo "  --limit=NUM                限制导出数量\n";
    echo "  --country=CODE             国家代码过滤 (如: US, CA, GB)\n";
    echo "  --start-date=DATE          开始日期 (YYYY-MM-DD)\n";
    echo "  --end-date=DATE            结束日期 (YYYY-MM-DD)\n";
    echo "  --help                     显示此帮助信息\n\n";
    echo "示例:\n";
    echo "  php export_successful_exchanges.php\n";
    echo "  php export_successful_exchanges.php --limit=1000\n";
    echo "  php export_successful_exchanges.php --country=US --output=us_success.csv\n";
    echo "  php export_successful_exchanges.php --start-date=2024-01-01 --end-date=2024-12-31\n";
}

// 主程序
try {
    $options = parseArgs();

    if (isset($options['help'])) {
        showHelp();
        exit(0);
    }

    $exporter = new SuccessfulExchangesExporter($options);
    $exporter->export();

} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
