<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddFixedItunesParam
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 固定传入参数
        $request->merge([
            'source' => 'brother-api', // 可以是固定值，或从配置/数据库读取
        ]);
        return $next($request);
    }
}
