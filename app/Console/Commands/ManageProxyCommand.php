<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ProxyService;
use Illuminate\Support\Facades\Cache;

class ManageProxyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'proxy:manage 
                            {action : 操作类型 (test|list|add|remove|clear)}
                            {--proxy= : 代理地址 (用于add/remove操作)}
                            {--file= : 代理文件路径 (用于批量导入)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '管理代理IP池';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'test':
                $this->testProxies();
                break;
            case 'list':
                $this->listProxies();
                break;
            case 'add':
                $this->addProxy();
                break;
            case 'remove':
                $this->removeProxy();
                break;
            case 'clear':
                $this->clearProxies();
                break;
            default:
                $this->error('未知操作: ' . $action);
                $this->showHelp();
                return 1;
        }

        return 0;
    }

    /**
     * 测试所有代理
     */
    protected function testProxies()
    {
        $this->info('开始测试代理IP...');
        
        $proxyList = ProxyService::getProxyList();
        
        if (empty($proxyList)) {
            $this->warn('没有配置代理IP');
            return;
        }

        $this->info('共找到 ' . count($proxyList) . ' 个代理IP');
        
        $progressBar = $this->output->createProgressBar(count($proxyList));
        $progressBar->start();

        $availableCount = 0;
        $failedCount = 0;

        foreach ($proxyList as $proxy) {
            $isAvailable = ProxyService::testProxy($proxy);
            
            if ($isAvailable) {
                $availableCount++;
            } else {
                $failedCount++;
            }
            
            $progressBar->advance();
            
            // 避免请求过于频繁
            usleep(500000); // 0.5秒
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("测试完成:");
        $this->line("✓ 可用代理: {$availableCount}");
        $this->line("✗ 不可用代理: {$failedCount}");
        $this->line("可用率: " . round(($availableCount / count($proxyList)) * 100, 2) . "%");
    }

    /**
     * 列出所有代理
     */
    protected function listProxies()
    {
        $proxyList = ProxyService::getProxyList();
        
        if (empty($proxyList)) {
            $this->warn('没有配置代理IP');
            $this->line('正在检查配置文件...');
            
            // 检查配置文件
            $configList = config('proxy.proxy_list', []);
            if (!empty($configList)) {
                $this->info('配置文件中找到 ' . count($configList) . ' 个代理IP');
                $this->line('建议运行: php artisan config:clear');
            } else {
                $this->error('配置文件中也没有找到代理IP');
                $this->line('请检查 config/proxy.php 文件');
            }
            return;
        }

        $this->info('当前代理IP列表:');
        
        foreach ($proxyList as $index => $proxy) {
            $this->line(($index + 1) . ". " . $proxy);
        }
        
        $this->info('共 ' . count($proxyList) . ' 个代理IP');
    }

    /**
     * 添加代理
     */
    protected function addProxy()
    {
        $proxy = $this->option('proxy');
        
        if (!$proxy) {
            $proxy = $this->ask('请输入代理地址 (格式: http://username:password@host:port)');
        }

        if (!$proxy) {
            $this->error('代理地址不能为空');
            return;
        }

        $proxyList = ProxyService::getProxyList();
        
        if (in_array($proxy, $proxyList)) {
            $this->warn('代理已存在');
            return;
        }

        $proxyList[] = $proxy;
        ProxyService::setProxyList($proxyList);
        
        $this->info('代理添加成功');
        $this->line("新增代理: {$proxy}");
    }

    /**
     * 移除代理
     */
    protected function removeProxy()
    {
        $proxy = $this->option('proxy');
        
        if (!$proxy) {
            $proxyList = ProxyService::getProxyList();
            
            if (empty($proxyList)) {
                $this->warn('没有配置代理IP');
                return;
            }

            $this->info('当前代理IP列表:');
            foreach ($proxyList as $index => $p) {
                $this->line(($index + 1) . ". " . $p);
            }

            $choice = $this->ask('请选择要移除的代理编号');
            $index = (int)$choice - 1;
            
            if ($index < 0 || $index >= count($proxyList)) {
                $this->error('无效的编号');
                return;
            }
            
            $proxy = $proxyList[$index];
        }

        $proxyList = ProxyService::getProxyList();
        $newList = array_filter($proxyList, function($p) use ($proxy) {
            return $p !== $proxy;
        });

        if (count($newList) === count($proxyList)) {
            $this->warn('代理不存在');
            return;
        }

        ProxyService::setProxyList(array_values($newList));
        
        $this->info('代理移除成功');
        $this->line("移除代理: {$proxy}");
    }

    /**
     * 清空代理列表
     */
    protected function clearProxies()
    {
        if (!$this->confirm('确定要清空所有代理IP吗？')) {
            return;
        }

        ProxyService::setProxyList([]);
        Cache::forget('proxy_current_index');
        
        $this->info('代理列表已清空');
    }

    /**
     * 显示帮助信息
     */
    protected function showHelp()
    {
        $this->info('代理管理命令使用说明:');
        $this->line('');
        $this->line('测试代理:');
        $this->line('  php artisan proxy:manage test');
        $this->line('');
        $this->line('列出代理:');
        $this->line('  php artisan proxy:manage list');
        $this->line('');
        $this->line('添加代理:');
        $this->line('  php artisan proxy:manage add --proxy="http://user:pass@host:port"');
        $this->line('');
        $this->line('移除代理:');
        $this->line('  php artisan proxy:manage remove --proxy="http://user:pass@host:port"');
        $this->line('');
        $this->line('清空代理:');
        $this->line('  php artisan proxy:manage clear');
    }
} 