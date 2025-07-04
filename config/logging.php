<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'wechat' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wechat.log'),
            'level' => 'debug',
            'days' => 7,
        ],

        // 礼品卡兑换日志
        'gift_card_exchange' => [
            'driver' => 'daily',
            'path' => storage_path('logs/gift_card_exchange.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        // 预报爬虫日志
        'forecast_crawler' => [
            'driver' => 'daily',
            'path' => storage_path('logs/forecast_crawler.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        // 账单处理日志
        'bill_processing' => [
            'driver' => 'daily',
            'path' => storage_path('logs/bill_processing.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        // 队列任务日志
        'queue_jobs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/queue_jobs.log'),
            'level' => 'debug',
            'days' => 7,
        ],

        // 卡密查询日志
        'card_query' => [
            'driver' => 'daily',
            'path' => storage_path('logs/card_query.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        // WebSocket账号监控日志
        'websocket_monitor' => [
            'driver' => 'daily',
            'path' => storage_path('logs/account_monitor.log'),
            'level' => env('WEBSOCKET_LOG_LEVEL', 'debug'),
            'days' => 7,
            'replace_placeholders' => true,
        ],

        // 脚本处理账号状态日志
        'kernel_process_accounts' => [
            'driver' => 'daily',
            'path' => storage_path('logs/kernel_process_accounts.log'),
            'level' => env('WEBSOCKET_LOG_LEVEL', 'debug'),
            'days' => 7,
            'replace_placeholders' => true,
        ],

        // 查码任务日志
        'verify_code_job' => [
            'driver' => 'daily',
            'path' => storage_path('logs/verify_code_job.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
        ]
    ],

];
