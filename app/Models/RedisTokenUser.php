<?php

namespace App\Models;

use ArrayAccess;
use Illuminate\Contracts\Auth\Authenticatable;

class RedisTokenUser implements Authenticatable, ArrayAccess
{
    public $id;
    public $username;
    public $roles;
    public $is_admin;
    public $login_time;
    protected $attributes = [];

    public function __construct($data = [])
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        $data = $data ?: [];
        $this->attributes = $data;  // 保存原始数据
        
        $this->id = $data['id'] ?? null;
        $this->username = $data['username'] ?? '';
        $this->roles = $data['roles'] ?? [];
        $this->is_admin = $data['is_admin'] ?? false;
        $this->login_time = $data['login_time'] ?? time();
    }

    public function setAttributes($attributes)
    {
        $this->attributes = $attributes;

        //将attributes转换为属性供外部访问
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
    }

    
    /**
     * @return string
     */
    public function getAuthIdentifierName()
    {
        // Return the name of unique identifier for the user (e.g. "id")
        return 'id';
    }

    /**
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        // Return the unique identifier for the user (e.g. their ID, 123)
        return $this->attributes[$this->getAuthIdentifierName()] ?? null;
    }

    /**
     * @return string
     */
    public function getAuthPassword()
    {
        // Returns the (hashed) password for the user
        return $this->attributes['password'] ?? null;
    }

    /**
     * @return string
     */
    public function getRememberToken()
    {
        // Return the token used for the "remember me" functionality
        return $this->attributes[$this->getRememberTokenName()] ?? null;
    }

    /**
     * @param  string $value
     * @return void
     */
    public function setRememberToken($value)
    {
        // Save a new token user for the "remember me" functionality
        $this->attributes[$this->getRememberTokenName()] = $value;
    }

    /**
     * @return string
     */
    public function getRememberTokenName()
    {
        // Return the name of the column / attribute used to store the "remember me" token
        return 'remember_token';
    }

    //实现ArrayAccess允许像数组字段一样访问

    public function offsetExists($offset) {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet($offset) {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet($offset, $value) {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->attributes[$offset]);
    }
}
