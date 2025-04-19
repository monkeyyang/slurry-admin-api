<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private function createToken($userId, $username)
    {
        // 获取用户角色
        $roles = DB::table('admin_user_role')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_user_role.role_id')
            ->where('admin_user_role.user_id', $userId)
            ->where('admin_roles.status', 'ENABLED')
            ->where('admin_roles.deleted', 0)
            ->pluck('admin_roles.key')
            ->toArray();

        $token = Str::random(32);
        $key = 'token:' . $token;
        $ttl = config('auth.token_ttl', 7200);

        $data = [
            'user_id' => $userId,
            'username' => $username,
            'roles' => $roles,  // 添加角色信息到用户数据中
            'login_time' => time(),
            'expire_time' => time() + $ttl
        ];

        Redis::setex($key, $ttl, json_encode($data));
        return $token;
    }
} 