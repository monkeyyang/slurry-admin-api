<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Services\ItunesTradeAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class RefreshInvalidLoginAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:refresh-invalid-login {--account=* : 指定特定的账号，支持多个账号} {--export= : 导出账号信息到指定的CSV文件} {--export-html= : 导出账号信息到指定的HTML文件（支持颜色格式）} {--export-only : 只导出不执行登录任务} {--limit= : 限制处理的账号数量，用于测试或分批处理}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '刷新登录状态为失效的处理中和等待中账号的登录状态';

    protected ItunesTradeAccountService $accountService;

    public function __construct(ItunesTradeAccountService $accountService)
    {
        parent::__construct();
        $this->accountService = $accountService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // 获取选项
        $specifiedAccounts = $this->option('account');
        $exportFile = $this->option('export');
        $exportHtmlFile = $this->option('export-html');
        $exportOnly = $this->option('export-only');
        $limit = $this->option('limit');

        if (!empty($specifiedAccounts)) {
            $this->info('开始处理指定账号...');
            $this->line('指定的账号: ' . implode(', ', $specifiedAccounts));
        } else {
            $this->info('开始处理失效登录状态的账号...');
        }

        try {
            // 获取需要处理的账号
            $accounts = $this->getAccountsNeedingLoginRefresh($specifiedAccounts);

            if ($accounts->isEmpty()) {
                if (!empty($specifiedAccounts)) {
                    $this->warn('没有找到符合条件的指定账号');
                } else {
                    $this->info('没有找到需要处理的账号');
                }
                return;
            }

                        // 应用数量限制（如果指定）
            if ($limit && is_numeric($limit) && $limit > 0) {
                $originalCount = $accounts->count();
                $accounts = $accounts->take($limit);
                $this->info("找到 {$originalCount} 个账号，限制处理前 {$limit} 个");
            } else {
                $this->info("找到 {$accounts->count()} 个账号");
            }
            
            // 显示查询条件用于调试
            if (!empty($specifiedAccounts)) {
                $this->line("查询条件：指定账号 - " . implode(', ', $specifiedAccounts));
            } else {
                $this->line("查询条件：状态为 processing 或 waiting，且登录状态为 invalid 或 NULL 的账号");
            }

            // 导出功能
            if ($exportFile) {
                $this->exportAccountsToCSV($accounts, $exportFile);
            }

            if ($exportHtmlFile) {
                $this->exportAccountsToHTML($accounts, $exportHtmlFile);
            }

            // 如果是只导出模式，则不执行登录任务
            if ($exportOnly) {
                $this->info('只导出模式，跳过登录任务创建');
                return;
            }

            // 准备登录任务数据
            $loginItems = $this->prepareLoginItems($accounts);

            if (empty($loginItems)) {
                $this->warn('没有有效的账号可以进行登录任务');
                return;
            }

            // 创建登录任务
            $this->createLoginTasks($loginItems);

            $this->info('登录任务创建完成');

        } catch (Exception $e) {
            $this->error('执行过程中发生错误: ' . $e->getMessage());
            Log::error('刷新失效登录账号失败: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取需要刷新登录的账号
     */
    private function getAccountsNeedingLoginRefresh(array $specifiedAccounts = [])
    {
        $this->info("开始查询符合条件的账号...");
        
        $query = ItunesTradeAccount::query()->with(['plan', 'country']);

        // 如果指定了特定账号，则只查询这些账号
        if (!empty($specifiedAccounts)) {
            $query->whereIn('account', $specifiedAccounts);
            $this->line("查询指定账号: " . implode(', ', $specifiedAccounts));
        } else {
            // 否则查询所有符合状态条件的账号
            $query->whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_WAITING
            ]);
            $this->line("查询状态为 processing 或 waiting 的账号");
        }

        // 登录状态为失效的账号
        $query->whereIn('login_status', [ItunesTradeAccount::STATUS_LOGIN_INVALID, NULL]);
        $this->line("登录状态为 invalid 或 NULL 的账号");

        // 添加调试信息：显示SQL查询
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $this->line("执行的SQL查询: " . $sql);
        $this->line("查询参数: " . json_encode($bindings));

        // 先获取总数
        $totalCount = $query->count();
        $this->info("数据库中符合条件的总记录数: {$totalCount}");

        // 获取数据
        $accounts = $query->get();
        $this->info("实际获取到的记录数: {$accounts->count()}");

        return $accounts;
    }

    /**
     * 准备登录任务数据
     */
    private function prepareLoginItems($accounts): array
    {
        $loginItems = [];

        foreach ($accounts as $account) {
            try {
                // 验证账号数据完整性
                if (empty($account->account) || empty($account->getDecryptedPassword())) {
                    $this->warn("账号 ID:{$account->id} 缺少必要信息，跳过");
                    continue;
                }

                $loginItems[] = [
                    'id' => $account->id,
                    'username' => $account->account,
                    'password' => $account->getDecryptedPassword(),
                    'VerifyUrl' => $account->api_url ?? ''
                ];

                $this->line("✓ 准备账号: {$account->account}");

            } catch (Exception $e) {
                $this->warn("处理账号 ID:{$account->id} 时出错: " . $e->getMessage());
                continue;
            }
        }

        return $loginItems;
    }

    /**
     * 创建登录任务
     */
    private function createLoginTasks(array $loginItems): void
    {
        // 分批处理，每批最多50个账号
        $batchSize = 50;
        $batches = array_chunk($loginItems, $batchSize);

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            $this->info("处理第 {$batchNum} 批，共 " . count($batch) . " 个账号");

            try {
                // 使用反射调用protected方法
                $reflection = new \ReflectionClass($this->accountService);
                $method = $reflection->getMethod('createLoginTask');
                $method->setAccessible(true);

                $result = $method->invoke($this->accountService, $batch);

                $this->info("第 {$batchNum} 批登录任务创建成功");

                // 记录任务ID（如果有的话）
                if (isset($result['task_id'])) {
                    $this->line("任务ID: {$result['task_id']}");
                }

                // 批次间稍作延迟，避免API压力
                if ($batchNum < count($batches)) {
                    sleep(2);
                }

            } catch (Exception $e) {
                $this->error("第 {$batchNum} 批登录任务创建失败: " . $e->getMessage());

                // 记录详细错误日志
                Log::error("批量创建登录任务失败", [
                    'batch_number' => $batchNum,
                    'accounts_in_batch' => array_column($batch, 'username'),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                continue;
            }
        }
    }

    /**
     * 导出账号信息到CSV文件
     */
    private function exportAccountsToCSV($accounts, string $filename): void
    {
        $this->info("开始导出账号信息到文件: {$filename}");
        $this->info("准备导出 {$accounts->count()} 个账号到CSV文件");

        try {
            // 增加内存限制以处理大量数据
            ini_set('memory_limit', '1024M');
            // 设置最大执行时间
            set_time_limit(300); // 5分钟
            // 确保目录存在
            $directory = dirname($filename);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // 打开文件写入
            $file = fopen($filename, 'w');
            if (!$file) {
                throw new Exception("无法创建文件: {$filename}");
            }

            // 写入UTF-8 BOM以支持中文
            fwrite($file, "\xEF\xBB\xBF");

            // 写入CSV头部
            $headers = [
                '账号',
                '密码',
                '接码地址',
                '金额',
                '状态',
                '登录状态',
                '当前计划天',
                '群聊名称',
                '创建时间'
            ];
            fputcsv($file, $headers);

            // 注意：群聊信息通过account->getRoomInfo()方法获取，无需批量预加载

            // 写入账号数据
            $processedCount = 0;
            $totalCount = $accounts->count();

            foreach ($accounts as $account) {
                try {
                    $processedCount++;

                    // 每处理100个账号显示一次进度
                    if ($processedCount % 100 == 0 || $processedCount == $totalCount) {
                        $this->line("CSV导出进度: {$processedCount}/{$totalCount}");
                    }
                    // 获取解密密码
                    $decryptedPassword = '';
                    try {
                        $decryptedPassword = $account->getDecryptedPassword();
                    } catch (Exception $e) {
                        $decryptedPassword = '解密失败';
                    }

                    // 获取接码地址
                    $apiUrl = $account->api_url ?? '';

                    // 翻译状态
                    $statusText = $this->translateStatus($account->status);

                    // 翻译登录状态
                    $loginStatusText = $this->translateLoginStatus($account->login_status);

                    // 获取当前计划天，如果为null则为1
                    $currentPlanDay = $account->current_plan_day ?? 1;

                    // 获取群聊名称 - 通过account的getRoomInfo方法
                    $roomName = '-';
                    try {
                        $roomInfo = $account->getRoomInfo();
                        if ($roomInfo && $roomInfo->room_name) {
                            $roomName = $roomInfo->room_name;
                        }
                    } catch (Exception $e) {
                        $roomName = '获取失败';
                    }

                    // 格式化创建时间
                    $createdAt = $account->created_at ? $account->created_at->format('Y-m-d H:i:s') : '';

                    // 写入CSV行
                    $row = [
                        $account->account,
                        $decryptedPassword,
                        $apiUrl,
                        '$' . number_format($account->amount ?? 0, 2),
                        $statusText,
                        $loginStatusText,
                        $currentPlanDay,
                        $roomName,
                        $createdAt
                    ];
                    fputcsv($file, $row);

                } catch (Exception $e) {
                    $this->warn("处理账号 {$account->account} 时出错: " . $e->getMessage());
                    Log::error("CSV导出处理账号失败", [
                        'account_id' => $account->id ?? 'unknown',
                        'account' => $account->account ?? 'unknown',
                        'error' => $e->getMessage(),
                        'processed_count' => $processedCount,
                        'total_count' => $totalCount
                    ]);
                    continue;
                }
            }

            fclose($file);

            $this->info("✓ 成功导出 {$processedCount} 个账号到 {$filename}");
            
            // 检查是否有数据丢失
            if ($processedCount < $totalCount) {
                $this->warn("⚠ 警告：预期导出 {$totalCount} 个账号，实际只导出了 {$processedCount} 个账号");
                $this->warn("可能的原因：处理过程中遇到错误或内存/时间限制");
            }

        } catch (Exception $e) {
            $this->error("导出失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 导出账号信息到HTML文件（支持颜色格式）
     */
    private function exportAccountsToHTML($accounts, string $filename): void
    {
        $this->info("开始导出账号信息到HTML文件: {$filename}");

        try {
            // 确保目录存在
            $directory = dirname($filename);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // 生成HTML内容
            $html = $this->generateHTMLContent($accounts);

            // 写入文件
            if (file_put_contents($filename, $html) === false) {
                throw new Exception("无法创建文件: {$filename}");
            }

            $this->info("✓ 成功导出 {$accounts->count()} 个账号到 {$filename}");

        } catch (Exception $e) {
            $this->error("HTML导出失败: " . $e->getMessage());
            throw $e;
        }
    }

        /**
     * 生成HTML内容
     */
    private function generateHTMLContent($accounts): string
    {
        // 增加内存限制以处理大量数据
        ini_set('memory_limit', '1024M');
        // 设置最大执行时间
        set_time_limit(300); // 5分钟
        
        $this->info("正在生成HTML内容，共 {$accounts->count()} 个账号...");
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iTunes账号信息导出</title>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .summary {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            word-wrap: break-word;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #495057;
            position: sticky;
            top: 0;
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        .status-valid {
            color: #28a745;
            font-weight: bold;
        }
        .status-invalid {
            color: #dc3545;
            font-weight: bold;
        }
        .amount {
            color: #28a745;
            font-weight: bold;
            font-family: "Courier New", monospace;
        }
        .account-email {
            color: #007bff;
            font-weight: 500;
        }
        .room-name {
            color: #6c757d;
            font-style: italic;
        }
        .export-time {
            text-align: center;
            color: #6c757d;
            margin-top: 20px;
            font-size: 14px;
        }
        .password-field {
            font-family: "Courier New", monospace;
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 3px;
        }
                 .api-url {
             max-width: 200px;
             overflow: hidden;
             text-overflow: ellipsis;
             white-space: nowrap;
             color: #6f42c1;
             position: relative;
         }
         .expandable {
             position: relative;
         }
         .expand-btn {
             color: #007bff;
             cursor: pointer;
             font-size: 12px;
             margin-left: 5px;
             text-decoration: underline;
             user-select: none;
         }
         .expand-btn:hover {
             color: #0056b3;
         }
         .expanded {
             max-width: none !important;
             white-space: normal !important;
             word-break: break-all;
         }
         .copy-hint {
             position: fixed;
             top: 20px;
             right: 20px;
             background-color: #28a745;
             color: white;
             padding: 8px 12px;
             border-radius: 4px;
             font-size: 14px;
             z-index: 1000;
             opacity: 0;
             transition: opacity 0.3s;
         }
         .copy-hint.show {
             opacity: 1;
         }
         .row-selected {
             background-color: #e3f2fd !important;
             border-left: 3px solid #2196f3;
         }
         .long-text {
             max-width: 150px;
             overflow: hidden;
             text-overflow: ellipsis;
             white-space: nowrap;
         }
         .password-field.long-text {
             max-width: 120px;
         }
         </style>
     <script>
         // 展开/收起功能
         function toggleExpand(btn) {
             const container = btn.parentElement;
             const textContent = container.querySelector(".text-content");
             const fullText = container.querySelector(".full-text");
             const isExpanded = fullText.style.display !== "none";

             if (isExpanded) {
                 textContent.style.display = "inline";
                 fullText.style.display = "none";
                 container.classList.remove("expanded");
                 btn.textContent = "展开";
             } else {
                 textContent.style.display = "none";
                 fullText.style.display = "inline";
                 container.classList.add("expanded");
                 btn.textContent = "收起";
             }
         }

         // 复制行数据功能
         function copyRowData(row) {
             try {
                 const rowData = JSON.parse(row.getAttribute("data-row"));
                 const textToCopy = rowData.join("\\t"); // 使用制表符分隔，便于粘贴到Excel

                 // 使用现代的 Clipboard API
                 if (navigator.clipboard && window.isSecureContext) {
                     navigator.clipboard.writeText(textToCopy).then(() => {
                         showCopyHint();
                         highlightRow(row);
                     });
                 } else {
                     // 降级方案：使用传统的复制方法
                     const textArea = document.createElement("textarea");
                     textArea.value = textToCopy;
                     textArea.style.position = "fixed";
                     textArea.style.left = "-999999px";
                     textArea.style.top = "-999999px";
                     document.body.appendChild(textArea);
                     textArea.focus();
                     textArea.select();

                     try {
                         document.execCommand("copy");
                         showCopyHint();
                         highlightRow(row);
                     } catch (err) {
                         console.error("复制失败:", err);
                         alert("复制失败，请手动选择并复制");
                     } finally {
                         document.body.removeChild(textArea);
                     }
                 }
             } catch (error) {
                 console.error("复制数据时出错:", error);
                 alert("复制失败，请重试");
             }
         }

         // 显示复制提示
         function showCopyHint() {
             const hint = document.getElementById("copyHint");
             hint.classList.add("show");
             setTimeout(() => {
                 hint.classList.remove("show");
             }, 2000);
         }

         // 高亮选中的行
         function highlightRow(row) {
             // 清除之前的高亮
             document.querySelectorAll(".row-selected").forEach(r => {
                 r.classList.remove("row-selected");
             });

             // 高亮当前行
             row.classList.add("row-selected");

             // 2秒后移除高亮
             setTimeout(() => {
                 row.classList.remove("row-selected");
             }, 2000);
         }
     </script>
 </head>
 <body>
     <div class="copy-hint" id="copyHint">已复制到剪贴板</div>
     <div class="container">
         <h1>iTunes账号信息导出报告</h1>

        <div class="summary">
            <strong>导出统计：</strong>共 ' . $accounts->count() . ' 个账号
            <br><strong>导出时间：</strong>' . now()->format('Y-m-d H:i:s') . '
        </div>

        <table>
            <thead>
                <tr>
                                         <th>序号</th>
                     <th>账号</th>
                     <th>密码</th>
                     <th>接码地址</th>
                     <th>金额</th>
                     <th>状态</th>
                     <th>登录状态</th>
                     <th>当前计划天</th>
                     <th>群聊名称</th>
                     <th>创建时间</th>
                </tr>
            </thead>
            <tbody>';

                $index = 1;
        $processedCount = 0;
        $totalCount = $accounts->count();
        $htmlRows = []; // 使用数组收集HTML行，最后一次性拼接
        
        foreach ($accounts as $account) {
            try {
                $processedCount++;
                
                // 每处理50个账号显示一次进度（更频繁的进度更新）
                if ($processedCount % 50 == 0 || $processedCount == $totalCount) {
                    $this->line("处理进度: {$processedCount}/{$totalCount}");
                }
                // 获取解密密码
                $decryptedPassword = '';
                try {
                    $decryptedPassword = $account->getDecryptedPassword();
                } catch (Exception $e) {
                    $decryptedPassword = '解密失败';
                }

                                                  // 获取接码地址
                 $apiUrl = $account->api_url ?? '';
                 $displayApiUrl = $apiUrl ? $apiUrl : '-';

                 // 翻译状态
                 $statusText = $this->translateStatus($account->status);

                 // 翻译登录状态并添加样式
                 $loginStatusText = $this->translateLoginStatus($account->login_status);
                 $loginStatusClass = match ($account->login_status) {
                     'valid' => 'status-valid',
                     'invalid' => 'status-invalid',
                     default => ''
                 };

                 // 获取当前计划天，如果为null则为1
                 $currentPlanDay = $account->current_plan_day ?? 1;

                 // 获取群聊名称
                 $roomName = '-';
                 try {
                     $roomInfo = $account->getRoomInfo();
                     if ($roomInfo && $roomInfo->room_name) {
                         $roomName = htmlspecialchars($roomInfo->room_name);
                     }
                 } catch (Exception $e) {
                     $roomName = '获取失败';
                 }

                 // 格式化金额
                 $formattedAmount = '$' . number_format($account->amount ?? 0, 2);

                 // 格式化创建时间
                 $createdAt = $account->created_at ? $account->created_at->format('Y-m-d H:i:s') : '-';

                 // 处理长文本字段
                 $passwordHtml = $this->generateExpandableText($decryptedPassword, 'password-field', 20);
                 $apiUrlHtml = $this->generateExpandableText($displayApiUrl, 'api-url', 30, $apiUrl);
                 $roomNameHtml = $this->generateExpandableText($roomName, 'room-name', 15);

                 // 构建行数据用于复制
                 $rowData = [
                     $index,
                     $account->account,
                     $decryptedPassword,
                     $apiUrl ?: '-',
                     $formattedAmount,
                     $statusText,
                     $loginStatusText,
                     $currentPlanDay,
                     $roomName,
                     $createdAt
                 ];
                 $rowDataJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');

                 // 将HTML行添加到数组中，而不是直接拼接字符串
                 $htmlRows[] = '<tr ondblclick="copyRowData(this)" data-row=\'' . $rowDataJson . '\'>
                     <td>' . $index . '</td>
                     <td class="account-email">' . htmlspecialchars($account->account) . '</td>
                     <td>' . $passwordHtml . '</td>
                     <td>' . $apiUrlHtml . '</td>
                     <td class="amount">' . $formattedAmount . '</td>
                     <td>' . htmlspecialchars($statusText) . '</td>
                     <td class="' . $loginStatusClass . '">' . $loginStatusText . '</td>
                     <td>' . $currentPlanDay . '</td>
                     <td>' . $roomNameHtml . '</td>
                     <td>' . $createdAt . '</td>
                 </tr>';

                 $index++;

            } catch (Exception $e) {
                $this->warn("处理账号 {$account->account} 时出错: " . $e->getMessage());
                Log::error("HTML导出处理账号失败", [
                    'account_id' => $account->id ?? 'unknown',
                    'account' => $account->account ?? 'unknown',
                    'error' => $e->getMessage(),
                    'processed_count' => $processedCount,
                    'total_count' => $totalCount
                ]);
                continue;
            }
        }
        
        $this->info("HTML内容生成完成，共处理 {$processedCount} 个账号");
        $this->info("正在拼接HTML内容...");

        // 一次性拼接所有HTML行，这比逐个拼接更高效
        $html .= implode("\n", $htmlRows);
        
        $html .= '</tbody>
        </table>

                 <div class="export-time">
             导出时间: ' . now()->format('Y-m-d H:i:s') . ' | 系统生成
             <br><small>💡 提示：双击表格行可复制整行数据，点击"展开"可查看完整内容</small>
         </div>
     </div>

     <script>
         // 页面加载完成后的初始化
         document.addEventListener("DOMContentLoaded", function() {
             console.log("iTunes账号导出页面已加载");
             console.log("使用说明：");
             console.log("1. 双击任意表格行可复制整行数据");
             console.log("2. 点击"展开"按钮可查看完整的长文本内容");
         });
     </script>
 </body>
 </html>';

                 return $html;
     }

    /**
     * 生成可展开的文本HTML
     */
    private function generateExpandableText(string $text, string $cssClass = '', int $maxLength = 30, string $fullText = ''): string
    {
        $displayText = $fullText ?: $text;
        $escapedText = htmlspecialchars($text);
        $escapedFullText = htmlspecialchars($displayText);

        // 如果文本不长，直接返回
        if (mb_strlen($text) <= $maxLength) {
            return '<span class="' . $cssClass . '">' . $escapedText . '</span>';
        }

        // 生成可展开的HTML
        $truncatedText = mb_substr($text, 0, $maxLength) . '...';
        $escapedTruncatedText = htmlspecialchars($truncatedText);

        return '<span class="expandable ' . $cssClass . ' long-text" title="' . $escapedFullText . '">
            <span class="text-content">' . $escapedTruncatedText . '</span>
            <span class="expand-btn" onclick="toggleExpand(this)">展开</span>
            <span class="full-text" style="display: none;">' . $escapedText . '</span>
        </span>';
    }

    /**
     * 翻译状态
     */
    private function translateStatus(?string $status): string
    {
        return match ($status) {
            'completed' => '已完成',
            'processing' => '处理中',
            'waiting' => '等待中',
            'locking' => '锁定中',
            null => '未知',
            default => $status
        };
    }

    /**
     * 翻译登录状态
     */
    private function translateLoginStatus(?string $loginStatus): string
    {
        return match ($loginStatus) {
            'valid' => '有效',
            'invalid' => '失效',
            null => '未知',
            default => $loginStatus
        };
    }
}
