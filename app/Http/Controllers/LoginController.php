<?php

namespace App\Http\Controllers;

use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class LoginController extends Controller
{
    public function captchaImage(Request $request)
    {
        $phraseBuilder = new PhraseBuilder(5, '0123456789abcdefhjkmnrstuvwxy');
        $builder = new CaptchaBuilder(null, $phraseBuilder);
        $builder->build();

        $captcha_info = [
            'captcha_code' => $builder->getPhrase()
        ];
        $captcha_info_JSON = $this->toJson($captcha_info);

        $captcha_key = uniqid();
        $redis_captcha_key = 'login:captcha_key:' . $captcha_key;
        $ttl = 60 * 10; //10分钟有效
        Redis::setex($redis_captcha_key, $ttl, $captcha_info_JSON);

        return $this->jsonOk([
            'captcha_key'=> $captcha_key,
            'captcha_img'=>base64_encode($builder->get()),
        ]);
    }

    public function login(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string|max:20|regex:/^\w+$/',
            'password' => 'required|string|max:255|regex:/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', //只包含 Base64 字符集中的字符，并且长度是4的倍数（以=结尾）
            'captcha_key' => 'required|string|max:20|regex:/^\w+$/',
            'captcha_code' => 'required|string|max:10|regex:/^\w+$/',
        ]);

        $username = $request->username;
        $password = $request->password;
        $captcha_key = $request->captcha_key;
        $captcha_code = $request->captcha_code;

        $decryptedData = $this->decryptPassword($password);

        $redis_captcha_key = 'login:captcha_key:' . $captcha_key;
        //从redis中取数据
        $captcha_info_JSON = Redis::get($redis_captcha_key);
        if(is_null($captcha_info_JSON)) {
            Log::error('captcha_key not found', ["captcha_key" => $captcha_key]);
            return $this->jsonError('验证码已过期');
        }

        $captcha_info = json_decode($captcha_info_JSON, true);
        if(empty($captcha_info)) {
            Log::error('captcha_info not found', ['captcha_info_JSON' => $captcha_info_JSON]);
            return $this->jsonError('验证码无效');
        }
        $server_captcha_code = $captcha_info['captcha_code'];
        if($server_captcha_code != $captcha_code) {
            return $this->jsonError('验证码错误');
        }

        $userinfo = DB::table('admin_users')
            ->select('id', 'username', 'password', 'is_admin', 'nickname', 'avatar', 'email')
            ->where('username', $username)
            ->first();

        if (is_null($userinfo)) {
            return $this->jsonError('用户名或密码错误');
        }

        if (!password_verify($decryptedData, $userinfo->password)) {
            return $this->jsonError('用户名或密码错误');
        }

        $userId = $userinfo->id;
        $token = $this->createToken($userId, $userinfo->username);

        // 获取用户角色信息
        $roles = DB::table('admin_user_role')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_user_role.role_id')
            ->where('admin_user_role.user_id', $userId)
            ->where('admin_roles.status', 'ENABLED')
            ->where('admin_roles.deleted', 0)
            ->select('admin_roles.id', 'admin_roles.name', 'admin_roles.key')
            ->get();

        // 获取角色对应的菜单权限
        $permissions = [];
        $roleKeys = [];
        
        foreach ($roles as $role) {
            $roleKeys[] = $role->key;
            
            // 获取角色对应的菜单权限
            $menuPermissions = DB::table('admin_role_menu')
                ->join('admin_menus', 'admin_menus.id', '=', 'admin_role_menu.menu_id')
                ->where('admin_role_menu.role_id', $role->id)
                ->where('admin_menus.status', 'ENABLED')
                ->where('admin_menus.deleted', 0)
                ->whereNotNull('admin_menus.permission')
                ->where('admin_menus.permission', '!=', '')
                ->pluck('admin_menus.permission')
                ->toArray();
            
            $permissions = array_merge($permissions, $menuPermissions);
        }
        
        // 去重权限
        $permissions = array_unique($permissions);
        
        // 如果是超级管理员，给予所有权限
        if ($userinfo->is_admin) {
            $permissions = ["*:*:*"];
            if (empty($roleKeys)) {
                $roleKeys[] = "admin";
            }
        }
        
        // 如果没有任何权限，至少给一个基本权限
        if (empty($permissions)) {
            $permissions[] = "common";
        }

        return $this->jsonOk([
            "token" => $token,
            "currentUser" => [
                "userInfo" => [
                    "id" => $userId,
                    "username" => $userinfo->username,
                    "nickname" => $userinfo->nickname,
                    "avatar" => $userinfo->avatar,
                    "email" => $userinfo->email,
                    "is_admin" => $userinfo->is_admin,
                ],
                "roleKeys" => $roleKeys,
                "permissions" => $permissions
            ]
        ]);
    }

    private function decryptPassword($originalPassword)
    {
        $privateKeyPath = base_path() . '/storage/rsa.key';
        $rsaPrivateKey = file_get_contents($privateKeyPath);
        $decryptBytes = openssl_private_decrypt(
            base64_decode($originalPassword),
            $decryptedData,
            $rsaPrivateKey,
            OPENSSL_PKCS1_PADDING
        );

        if (!$decryptBytes) {
            // 处理解密失败的情况
            return "Decryption failed";
        }

        return $decryptedData;
    }

    private function createToken($userId, $username): string
    {
        //考虑重复登录的情况，删除相同用户已经存在的token
//        $oldToken = Redis::get("admin:user:token:$userId");
//        if(! is_null($oldToken)) {
//            Redis::del("token:$oldToken");
//        }

        $token = md5(uniqid(mt_rand(), true));
        
        // 获取用户角色
        $roles = DB::table('admin_user_role')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_user_role.role_id')
            ->where('admin_user_role.user_id', $userId)
            ->where('admin_roles.status', 'ENABLED')
            ->where('admin_roles.deleted', 0)
            ->pluck('admin_roles.key')
            ->toArray();

        $userinfo = [
            'id' => $userId,
            'username' => $username,
            'roles' => $roles,  // 添加角色信息
            'is_admin' => 0,    // 默认非管理员
            'login_time' => time()
        ];

        // 如果角色中包含 admin，设置 is_admin 为 1
        if (in_array('admin', $roles)) {
            $userinfo['is_admin'] = 1;
        }
        
        $userinfoJSON = $this->toJson($userinfo);
        $ttl = 60 * 60 * 24 * 30; //30天
        
        Redis::setex("admin:token:$token", $ttl, $userinfoJSON);
        Redis::setex("admin:user:token:$userId", $ttl, $token);

        return $token;
    }

}
