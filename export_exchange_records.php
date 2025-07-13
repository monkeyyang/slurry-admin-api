<?php

/**
 * 导出兑换成功记录脚本
 * 
 * 功能：导出兑换成功的记录，包含以下字段：
 * - 兑换码
 * - 国家
 * - 金额
 * - 账号余款
 * - 账号
 * - 错误信息
 * - 执行状态
 * - 时间
 * - 群聊
 * - 计划
 * - 汇率
 * 
 * 用法：
 * php export_exchange_records.php --table=itunes_trade_account_logs --output=csv --file=exchange_records.csv
 * php export_exchange_records.php --table=gift_card_exchange_records --output=json --file=exchange_records.json
 * php export_exchange_records.php --table=all --output=xlsx --file=exchange_records.xlsx
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradeAccountLog;
use App\Models\GiftCardExchangeRecord;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Models\MrRoom;
use App\Models\Countries;
use Illuminate\Support\Collection;

// 初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class ExchangeRecordsExporter
{
    private array $options = [];
    
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'table' => 'itunes_trade_account_logs',
            'output' => 'csv',
            'file' => 'exchange_records.csv',
            'status' => 'success',
            'start_date' => null,
            'end_date' => null,
            'limit' => null,
            'country' => null,
            'account' => null,
            'plan_id' => null,
            'rate_id' => null,
        ], $options);
    }

    /**
     * 主要导出方法
     */
    public function export(): void
    {
        echo "开始导出兑换记录...\n";
        
        $records = [];
        
        if ($this->options['table'] === 'all') {
            $records = array_merge(
                $this->getItunesTradeAccountLogs(),
                $this->getGiftCardExchangeRecords()
            );
        } elseif ($this->options['table'] === 'itunes_trade_account_logs') {
            $records = $this->getItunesTradeAccountLogs();
        } elseif ($this->options['table'] === 'gift_card_exchange_records') {
            $records = $this->getGiftCardExchangeRecords();
        } else {
            throw new Exception("不支持的表类型: {$this->options['table']}");
        }
        
        if (empty($records)) {
            echo "没有找到符合条件的记录\n";
            return;
        }
        
        echo "找到 " . count($records) . " 条记录\n";
        
        // 导出数据
        $this->exportData($records);
        
        echo "导出完成！文件保存在: {$this->options['file']}\n";
    }

    /**
     * 获取iTunes交易账户日志记录
     */
    private function getItunesTradeAccountLogs(): array
    {
        $query = ItunesTradeAccountLog::query()
            ->with(['account', 'plan', 'rate'])
            ->where('status', $this->options['status']);
        
        // 应用过滤条件
        $this->applyFilters($query);
        
        // 按时间排序
        $query->orderBy('exchange_time', 'desc');
        
        // 应用限制
        if ($this->options['limit']) {
            $query->limit($this->options['limit']);
        }
        
        $logs = $query->get();
        
        echo "从 itunes_trade_account_logs 表获取到 " . $logs->count() . " 条记录\n";
        
        return $logs->map(function ($log) {
            return $this->formatItunesTradeAccountLog($log);
        })->toArray();
    }

    /**
     * 获取礼品卡兑换记录
     */
    private function getGiftCardExchangeRecords(): array
    {
        $query = GiftCardExchangeRecord::query()
            ->with(['plan', 'item', 'task'])
            ->where('status', $this->options['status']);
        
        // 应用过滤条件
        if ($this->options['start_date']) {
            $query->where('exchange_time', '>=', $this->options['start_date']);
        }
        
        if ($this->options['end_date']) {
            $query->where('exchange_time', '<=', $this->options['end_date']);
        }
        
        if ($this->options['country']) {
            $query->where('country_code', $this->options['country']);
        }
        
        if ($this->options['plan_id']) {
            $query->where('plan_id', $this->options['plan_id']);
        }
        
        // 按时间排序
        $query->orderBy('exchange_time', 'desc');
        
        // 应用限制
        if ($this->options['limit']) {
            $query->limit($this->options['limit']);
        }
        
        $records = $query->get();
        
        echo "从 gift_card_exchange_records 表获取到 " . $records->count() . " 条记录\n";
        
        return $records->map(function ($record) {
            return $this->formatGiftCardExchangeRecord($record);
        })->toArray();
    }

    /**
     * 格式化iTunes交易账户日志记录
     */
    private function formatItunesTradeAccountLog(ItunesTradeAccountLog $log): array
    {
        // 获取账号信息
        $account = $log->account;
        $accountName = $account ? $account->account : '未知账号';
        
        // 获取计划信息
        $plan = $log->plan;
        $planName = $plan ? $plan->name : '未知计划';
        
        // 获取汇率信息
        $rate = $log->rate;
        $rateValue = $rate ? $rate->rate : '未知汇率';
        
        // 获取群聊信息
        $roomName = '未知群聊';
        if ($log->room_id) {
            $roomInfo = MrRoom::where('room_id', $log->room_id)->first();
            if ($roomInfo) {
                $roomName = $roomInfo->room_name;
            }
        }
        
        // 获取国家信息
        $countryName = $log->country_code;
        $country = Countries::where('code', $log->country_code)->first();
        if ($country) {
            $countryName = $country->name;
        }
        
        return [
            'table_source' => 'itunes_trade_account_logs',
            'id' => $log->id,
            'exchange_code' => $log->code,
            'country' => $countryName,
            'country_code' => $log->country_code,
            'amount' => $log->amount,
            'account_balance' => $log->after_amount,
            'account' => $accountName,
            'error_message' => $log->error_message,
            'status' => $log->status,
            'status_text' => $log->status_text,
            'exchange_time' => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : '',
            'room_name' => $roomName,
            'room_id' => $log->room_id,
            'plan_name' => $planName,
            'plan_id' => $log->plan_id,
            'rate' => $rateValue,
            'rate_id' => $log->rate_id,
            'day' => $log->day,
            'wxid' => $log->wxid,
            'msgid' => $log->msgid,
            'batch_id' => $log->batch_id,
            'created_at' => $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '',
            'updated_at' => $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : '',
        ];
    }

    /**
     * 格式化礼品卡兑换记录
     */
    private function formatGiftCardExchangeRecord(GiftCardExchangeRecord $record): array
    {
        // 获取计划信息
        $plan = $record->plan;
        $planName = $plan ? $plan->name : '未知计划';
        
        // 获取账号信息
        $accountName = $record->account ?: '未知账号';
        
        // 获取国家信息
        $countryName = $record->country_code;
        $country = Countries::where('code', $record->country_code)->first();
        if ($country) {
            $countryName = $country->name;
        }
        
        return [
            'table_source' => 'gift_card_exchange_records',
            'id' => $record->id,
            'exchange_code' => $record->card_number,
            'country' => $countryName,
            'country_code' => $record->country_code,
            'amount' => $record->original_balance,
            'account_balance' => $record->converted_amount,
            'account' => $accountName,
            'error_message' => '',
            'status' => $record->status,
            'status_text' => $record->status === 'success' ? '成功' : '失败',
            'exchange_time' => $record->exchange_time ? $record->exchange_time->format('Y-m-d H:i:s') : '',
            'room_name' => '',
            'room_id' => '',
            'plan_name' => $planName,
            'plan_id' => $record->plan_id,
            'rate' => $record->exchange_rate,
            'rate_id' => '',
            'day' => '',
            'wxid' => '',
            'msgid' => '',
            'batch_id' => '',
            'created_at' => $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : '',
            'updated_at' => $record->updated_at ? $record->updated_at->format('Y-m-d H:i:s') : '',
        ];
    }

    /**
     * 应用过滤条件
     */
    private function applyFilters($query): void
    {
        if ($this->options['start_date']) {
            $query->where('exchange_time', '>=', $this->options['start_date']);
        }
        
        if ($this->options['end_date']) {
            $query->where('exchange_time', '<=', $this->options['end_date']);
        }
        
        if ($this->options['country']) {
            $query->where('country_code', $this->options['country']);
        }
        
        if ($this->options['account']) {
            $query->whereHas('account', function ($q) {
                $q->where('account', 'like', '%' . $this->options['account'] . '%');
            });
        }
        
        if ($this->options['plan_id']) {
            $query->where('plan_id', $this->options['plan_id']);
        }
        
        if ($this->options['rate_id']) {
            $query->where('rate_id', $this->options['rate_id']);
        }
    }

    /**
     * 导出数据到文件
     */
    private function exportData(array $records): void
    {
        switch ($this->options['output']) {
            case 'csv':
                $this->exportToCsv($records);
                break;
            case 'json':
                $this->exportToJson($records);
                break;
            case 'xlsx':
                $this->exportToXlsx($records);
                break;
            default:
                throw new Exception("不支持的输出格式: {$this->options['output']}");
        }
    }

    /**
     * 导出到CSV文件
     */
    private function exportToCsv(array $records): void
    {
        $file = fopen($this->options['file'], 'w');
        
        // 添加UTF-8 BOM以确保Excel正确显示中文
        fwrite($file, "\xEF\xBB\xBF");
        
        // 写入标题行
        $headers = [
            'ID',
            '数据来源',
            '兑换码',
            '国家',
            '国家代码',
            '金额',
            '账号余款',
            '账号',
            '错误信息',
            '执行状态',
            '状态文本',
            '兑换时间',
            '群聊名称',
            '群聊ID',
            '计划名称',
            '计划ID',
            '汇率',
            '汇率ID',
            '天数',
            '微信ID',
            '消息ID',
            '批次ID',
            '创建时间',
            '更新时间',
        ];
        
        fputcsv($file, $headers);
        
        // 写入数据行
        foreach ($records as $record) {
            $row = [
                $record['id'],
                $record['table_source'],
                $record['exchange_code'],
                $record['country'],
                $record['country_code'],
                $record['amount'],
                $record['account_balance'],
                $record['account'],
                $record['error_message'],
                $record['status'],
                $record['status_text'],
                $record['exchange_time'],
                $record['room_name'],
                $record['room_id'],
                $record['plan_name'],
                $record['plan_id'],
                $record['rate'],
                $record['rate_id'],
                $record['day'],
                $record['wxid'],
                $record['msgid'],
                $record['batch_id'],
                $record['created_at'],
                $record['updated_at'],
            ];
            
            fputcsv($file, $row);
        }
        
        fclose($file);
    }

    /**
     * 导出到JSON文件
     */
    private function exportToJson(array $records): void
    {
        $data = [
            'export_time' => date('Y-m-d H:i:s'),
            'total_records' => count($records),
            'filters' => $this->options,
            'records' => $records,
        ];
        
        file_put_contents($this->options['file'], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 导出到Excel文件（需要安装PhpSpreadsheet）
     */
    private function exportToXlsx(array $records): void
    {
        // 检查PhpSpreadsheet是否已安装
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            echo "警告：PhpSpreadsheet未安装，将使用CSV格式导出\n";
            $this->options['file'] = str_replace('.xlsx', '.csv', $this->options['file']);
            $this->exportToCsv($records);
            return;
        }
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // 设置标题行
        $headers = [
            'ID', '数据来源', '兑换码', '国家', '国家代码', '金额', '账号余款', '账号',
            '错误信息', '执行状态', '状态文本', '兑换时间', '群聊名称', '群聊ID',
            '计划名称', '计划ID', '汇率', '汇率ID', '天数', '微信ID', '消息ID',
            '批次ID', '创建时间', '更新时间'
        ];
        
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        
        // 设置数据行
        foreach ($records as $rowIndex => $record) {
            $row = [
                $record['id'],
                $record['table_source'],
                $record['exchange_code'],
                $record['country'],
                $record['country_code'],
                $record['amount'],
                $record['account_balance'],
                $record['account'],
                $record['error_message'],
                $record['status'],
                $record['status_text'],
                $record['exchange_time'],
                $record['room_name'],
                $record['room_id'],
                $record['plan_name'],
                $record['plan_id'],
                $record['rate'],
                $record['rate_id'],
                $record['day'],
                $record['wxid'],
                $record['msgid'],
                $record['batch_id'],
                $record['created_at'],
                $record['updated_at'],
            ];
            
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $value);
            }
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($this->options['file']);
    }
}

// 解析命令行参数
function parseArgs(): array
{
    $options = [];
    $args = $GLOBALS['argv'];
    
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
    echo "  php export_exchange_records.php [选项]\n\n";
    echo "选项:\n";
    echo "  --table=TABLE              数据表类型 (itunes_trade_account_logs|gift_card_exchange_records|all)\n";
    echo "  --output=FORMAT            输出格式 (csv|json|xlsx)\n";
    echo "  --file=FILE                输出文件名\n";
    echo "  --status=STATUS            状态过滤 (success|failed|pending)\n";
    echo "  --start-date=DATE          开始日期 (YYYY-MM-DD)\n";
    echo "  --end-date=DATE            结束日期 (YYYY-MM-DD)\n";
    echo "  --country=CODE             国家代码过滤\n";
    echo "  --account=ACCOUNT          账号过滤\n";
    echo "  --plan-id=ID               计划ID过滤\n";
    echo "  --rate-id=ID               汇率ID过滤\n";
    echo "  --limit=NUM                限制导出数量\n";
    echo "  --help                     显示此帮助信息\n\n";
    echo "示例:\n";
    echo "  php export_exchange_records.php --table=itunes_trade_account_logs --output=csv --file=success_records.csv\n";
    echo "  php export_exchange_records.php --table=all --output=xlsx --file=all_records.xlsx --start-date=2024-01-01\n";
    echo "  php export_exchange_records.php --table=gift_card_exchange_records --output=json --country=US --limit=1000\n";
}

// 主程序
try {
    $options = parseArgs();
    
    if (isset($options['help'])) {
        showHelp();
        exit(0);
    }
    
    $exporter = new ExchangeRecordsExporter($options);
    $exporter->export();
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
} 