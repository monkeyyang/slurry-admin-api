<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradeAccount;
use App\Services\GiftCardApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// 初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class SmallAmountAccountCleaner
{
    private GiftCardApiClient $giftCardApiClient;
    
    public function __construct()
    {
        $this->giftCardApiClient = new GiftCardApiClient();
    }
    
    public function run()
    {
        echo "=== 清理小额账号脚本 ===\n";
        echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";
        
        try {
            // 查询符合条件的账号
            $accounts = $this->getSmallAmountAccounts();
            
            if ($accounts->isEmpty()) {
                echo "✅ 没有找到需要清理的小额账号\n";
                return;
            }
            
            echo "找到 {$accounts->count()} 个小额账号需要清理\n\n";
            
            // 显示账号列表
            $this->displayAccountList($accounts);
            
            // 确认操作
            if (!$this->confirmOperation($accounts->count())) {
                echo "❌ 操作已取消\n";
                return;
            }
            
            // 执行清理
            $result = $this->cleanupAccounts($accounts);
            
            // 显示结果
            $this->displayResult($result);
            
        } catch (Exception $e) {
            echo "❌ 脚本执行异常: " . $e->getMessage() . "\n";
            Log::error('清理小额账号脚本异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
    }
    
    private function getSmallAmountAccounts()
    {
        echo "正在查询小额账号...\n";
        
        $accounts = ItunesTradeAccount::where('amount', '>', 0)
            ->where('amount', '<', 20)
            ->whereNull('deleted_at')
            ->orderBy('amount', 'asc')
            ->orderBy('id', 'asc')
            ->get();
            
        echo "查询完成，找到 {$accounts->count()} 个账号\n";
        
        return $accounts;
    }
    
    private function displayAccountList($accounts)
    {
        echo "--- 待清理账号列表 ---\n";
        printf("%-6s %-30s %-10s %-12s %-15s %-20s\n", 
            'ID', '账号邮箱', '余额', '状态', '登录状态', '更新时间');
        echo str_repeat('-', 90) . "\n";
        
        foreach ($accounts as $account) {
            printf("%-6d %-30s %-10.2f %-12s %-15s %-20s\n",
                $account->id,
                substr($account->account, 0, 30),
                $account->amount,
                $account->status,
                $account->login_status,
                $account->updated_at->format('Y-m-d H:i:s')
            );
        }
        echo "\n";
    }
    
    private function confirmOperation(int $count): bool
    {
        echo "⚠️  警告：此操作将删除 {$count} 个账号并调用登出接口，操作不可逆！\n";
        echo "请输入 'yes' 确认继续，输入其他任何内容取消: ";
        
        $handle = fopen("php://stdin", "r");
        $input = trim(fgets($handle));
        fclose($handle);
        
        return strtolower($input) === 'yes';
    }
    
    private function cleanupAccounts($accounts): array
    {
        $result = [
            'total' => $accounts->count(),
            'logout_success' => 0,
            'logout_failed' => 0,
            'delete_success' => 0,
            'delete_failed' => 0,
            'errors' => []
        ];
        
        echo "开始清理账号...\n\n";
        
        foreach ($accounts as $index => $account) {
            $accountInfo = "账号 {$account->id} ({$account->account})";
            echo "[" . ($index + 1) . "/{$result['total']}] 处理 {$accountInfo}\n";
            
            try {
                // 参考ItunesTradeAccountService::deleteAccount的逻辑
                $success = $this->deleteAccountWithLogout($account);
                
                if ($success) {
                    $result['logout_success']++;
                    $result['delete_success']++;
                    echo "  ✅ 登出并删除成功\n";
                } else {
                    $result['logout_failed']++;
                    $result['delete_failed']++;
                    echo "  ❌ 登出或删除失败\n";
                    $result['errors'][] = "{$accountInfo} 登出或删除失败";
                }
                
            } catch (Exception $e) {
                $result['logout_failed']++;
                $result['delete_failed']++;
                $error = "处理异常: " . $e->getMessage();
                echo "  ❌ {$error}\n";
                $result['errors'][] = "{$accountInfo} {$error}";
            }
            
            echo "\n";
            usleep(100000); // 100ms延迟
        }
        
        return $result;
    }
    
    /**
     * 删除账号并登出（参考ItunesTradeAccountService::deleteAccount）
     */
    private function deleteAccountWithLogout(ItunesTradeAccount $account): bool
    {
        try {
            // 1. 先调用登出接口
            $loginAccount = [
                'username' => $account->account,
            ];
            
            $deleteLoginResponse = $this->giftCardApiClient->deleteUserLogins($loginAccount);
            
            // 记录登出结果
            Log::channel('websocket_monitor')->info('删除的账号：' . json_encode($deleteLoginResponse));
            
            // 2. 然后删除账号（软删除）
            $deleteResult = $account->delete();
            
            if ($deleteResult) {
                Log::info('成功删除小额账号', [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'amount' => $account->amount,
                    'status' => $account->status,
                    'login_status' => $account->login_status,
                    'logout_response' => $deleteLoginResponse
                ]);
                
                return true;
            } else {
                Log::error('删除小额账号失败', [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'reason' => '数据库删除操作失败'
                ]);
                
                return false;
            }
            
        } catch (Exception $e) {
            Log::error('删除小额账号异常', [
                'account_id' => $account->id,
                'account_email' => $account->account,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
    
    private function displayResult(array $result)
    {
        echo "=== 清理结果统计 ===\n";
        
        printf("%-12s %-8s %-8s %-8s\n", "操作", "成功", "失败", "总计");
        echo str_repeat('-', 40) . "\n";
        printf("%-12s %-8d %-8d %-8d\n", "登出并删除", $result['delete_success'], $result['delete_failed'], $result['total']);
        
        $successRate = $result['total'] > 0 ? round(($result['delete_success'] / $result['total']) * 100, 1) : 0;
        
        echo "\n成功率统计:\n";
        echo "  整体成功率: {$successRate}%\n";
        
        if (!empty($result['errors'])) {
            echo "\n错误详情:\n";
            foreach ($result['errors'] as $index => $error) {
                echo "  " . ($index + 1) . ". {$error}\n";
            }
        }
        
        if ($result['delete_success'] == $result['total']) {
            echo "\n✅ 所有账号清理完成！\n";
        } else {
            echo "\n⚠️  部分账号清理失败，请检查错误日志\n";
        }
    }
}

// 解析命令行参数
$options = getopt('', ['help', 'dry-run']);

if (isset($options['help'])) {
    echo "使用方法:\n";
    echo "  php cleanup_small_amount_accounts.php [选项]\n\n";
    echo "选项:\n";
    echo "  --help      显示此帮助信息\n";
    echo "  --dry-run   仅查询账号，不执行删除操作\n\n";
    echo "功能说明:\n";
    echo "  查询条件: amount > 0 AND amount < 20 AND deleted_at IS NULL\n";
    echo "  删除逻辑: 参考ItunesTradeAccountService::deleteAccount\n";
    echo "  操作步骤: 1.调用登出接口 2.软删除账号 3.记录操作日志\n\n";
    exit(0);
}

if (isset($options['dry-run'])) {
    echo "=== 干运行模式（仅查询，不执行删除）===\n\n";
    
    $accounts = ItunesTradeAccount::where('amount', '>', 0)
        ->where('amount', '<', 20)
        ->whereNull('deleted_at')
        ->orderBy('amount', 'asc')
        ->orderBy('id', 'asc')
        ->get();
        
    if ($accounts->isEmpty()) {
        echo "✅ 没有找到需要清理的小额账号\n";
    } else {
        echo "找到 {$accounts->count()} 个小额账号:\n\n";
        
        printf("%-6s %-30s %-10s %-12s %-15s %-20s\n", 
            'ID', '账号邮箱', '余额', '状态', '登录状态', '更新时间');
        echo str_repeat('-', 90) . "\n";
        
        foreach ($accounts as $account) {
            printf("%-6d %-30s %-10.2f %-12s %-15s %-20s\n",
                $account->id,
                substr($account->account, 0, 30),
                $account->amount,
                $account->status,
                $account->login_status,
                $account->updated_at->format('Y-m-d H:i:s')
            );
        }
    }
    exit(0);
}

// 正常执行
try {
    $cleaner = new SmallAmountAccountCleaner();
    $cleaner->run();
    
} catch (Exception $e) {
    echo "脚本启动失败: " . $e->getMessage() . "\n";
    exit(1);
} 