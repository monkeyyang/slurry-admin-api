<?php
namespace App\Http\Middleware;

use Closure;


class DisableRequestBodyParsing
{
    public function handle($request, Closure $next)
    {
        // 禁用自动解析 JSON 请求体
        return $next($request);
    }
}
