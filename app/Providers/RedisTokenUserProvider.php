<?php

namespace App\Providers;

use App\Models\RedisTokenUser;
use Illuminate\Contracts\Auth\UserProvider as IlluminateUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Redis;

class RedisTokenUserProvider implements IlluminateUserProvider
{
    /**
     * @param mixed $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier)
    {
        // Get and return a user by their unique identifier
        return new RedisTokenUser(['id' => $identifier]); // 传入包含ID的数组
    }

    /**
     * @param mixed $identifier
     * @param string $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByToken($identifier, $token)
    {
        // Get and return a user by their unique identifier and "remember me" token
    }

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param string $token
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, $token)
    {
        // Save the given "remember me" token for the given user
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param array $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials)
    {
        //根据通行证去返回用户信息
        if (empty($credentials['api_token'])) {
            return new RedisTokenUser([]); // 传入空数组作为参数
        }
        $token = $credentials['api_token'];
        $key = "admin:token:$token";
        
        $userJSON = Redis::get($key);
        if (is_null($userJSON)) {
            return new RedisTokenUser([]); // 传入空数组作为参数
        }
    
        $userData = json_decode($userJSON, true);
        if (empty($userData)) {
            return new RedisTokenUser([]); // 传入空数组作为参数
        }
    
        return new RedisTokenUser($userData); // 传入用户数据
        // $user = null;
        // if (isset($credentials['api_token'])) {

        //     $token = $credentials['api_token'];
        //     if (!is_null($token) && strlen($token) == 32) {

        //         $userinfoJSON = Redis::get("admin:token:$token");
        //         if (!is_null($userinfoJSON)) {
        //             $userinfo = json_decode($userinfoJSON, true);
        //             if (!empty($userinfo)) {
        //                 $user = new RedisTokenUser();
        //                 $user->setAttributes($userinfo);
        //             }
        //         }
        //     }
        // }
        // return $user;
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param array $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        // Check that given credentials belong to the given user
        return true;
    }

}
