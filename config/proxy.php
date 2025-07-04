<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 代理IP配置
    |--------------------------------------------------------------------------
    |
    | 这里配置用于查码的代理IP列表
    | 格式: 'http://username:password@host:port' 或 'http://host:port'
    |
    */
    
    'proxy_list' => [
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
        'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    ],

    /*
    |--------------------------------------------------------------------------
    | 代理测试配置
    |--------------------------------------------------------------------------
    |
    | 用于测试代理IP是否可用的配置
    |
    */
    
    'test_url' => 'http://httpbin.org/ip',
    'test_timeout' => 10,
    
    /*
    |--------------------------------------------------------------------------
    | 代理轮询配置
    |--------------------------------------------------------------------------
    |
    | 代理IP轮询使用的配置
    |
    */
    
    'cache_key' => 'proxy_current_index',
    'cache_ttl' => 3600, // 1小时
    
    /*
    |--------------------------------------------------------------------------
    | 查码请求配置
    |--------------------------------------------------------------------------
    |
    | 查码请求的相关配置
    |
    */
    
    'verify_timeout' => 60, // 查码超时时间（秒）
    'verify_interval' => 5, // 查码间隔时间（秒）
    'request_timeout' => 10, // 单次请求超时时间（秒）
]; 