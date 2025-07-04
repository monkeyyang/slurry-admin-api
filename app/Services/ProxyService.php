<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProxyService
{
    /**
     * 获取代理IP
     */
    public static function getProxy()
    {
        // 从配置中获取代理IP列表
        $proxyList = self::getProxyList();
        
        if (empty($proxyList)) {
            return null;
        }

        // 从缓存中获取当前使用的代理索引
        $currentIndex = Cache::get('proxy_current_index', 0);
        
        // 获取代理IP
        $proxy = $proxyList[$currentIndex] ?? null;
        
        if ($proxy) {
            // 更新索引，轮询使用
            $nextIndex = ($currentIndex + 1) % count($proxyList);
            Cache::put('proxy_current_index', $nextIndex, 3600); // 1小时过期
            
            Log::info('使用代理IP', ['proxy' => $proxy]);
        }
        
        return $proxy;
    }

    /**
     * 测试代理IP是否可用
     */
    public static function testProxy($proxy)
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(config('proxy.test_timeout', 10))
                ->withOptions(['proxy' => $proxy])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                ])
                ->get(config('proxy.test_url', 'http://httpbin.org/ip'));
            
            if ($response->successful()) {
                Log::info('代理IP测试成功', [
                    'proxy' => $proxy,
                    'response_time' => $response->handlerStats()['total_time'] ?? 0
                ]);
                return true;
            } else {
                Log::warning('代理IP测试失败', [
                    'proxy' => $proxy,
                    'status' => $response->status()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::warning('代理IP测试异常', [
                'proxy' => $proxy,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取所有可用代理
     */
    public static function getAvailableProxies()
    {
        $proxyList = self::getProxyList();
        $availableProxies = [];
        
        foreach ($proxyList as $proxy) {
            if (self::testProxy($proxy)) {
                $availableProxies[] = $proxy;
            }
        }
        
        return $availableProxies;
    }

    /**
     * 设置代理IP列表
     */
    public static function setProxyList($proxyList)
    {
        // 这里可以将代理列表保存到数据库或配置文件
        // 暂时使用缓存存储
        Cache::put('proxy_list', $proxyList, 86400); // 24小时过期
    }

    /**
     * 获取当前代理列表
     */
    public static function getProxyList()
    {
        // 优先从缓存获取，如果没有则从配置文件获取
        $cachedList = Cache::get('proxy_list');
        if ($cachedList !== null) {
            return $cachedList;
        }
        
        // 从配置文件获取
        $configList = config('proxy.proxy_list', []);
        
        // 如果配置文件中有代理，则缓存起来
        if (!empty($configList)) {
            Cache::put('proxy_list', $configList, 86400); // 24小时过期
        }
        
        return $configList;
    }
} 