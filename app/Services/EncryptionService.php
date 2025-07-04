<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class EncryptionService
{
    /**
     * 加密敏感数据
     *
     * @param string $data
     * @return string
     */
    public static function encrypt($data)
    {
        if (empty($data)) {
            return $data;
        }
        
        return Crypt::encryptString($data);
    }

    /**
     * 解密敏感数据
     *
     * @param string $encryptedData
     * @return string
     */
    public static function decrypt($encryptedData)
    {
        if (empty($encryptedData)) {
            return $encryptedData;
        }
        
        try {
            return Crypt::decryptString($encryptedData);
        } catch (\Exception $e) {
            // 如果解密失败，可能数据未加密，直接返回原数据
            return $encryptedData;
        }
    }

    /**
     * 批量加密数组中的敏感字段
     *
     * @param array $data
     * @param array $sensitiveFields
     * @return array
     */
    public static function encryptArray($data, $sensitiveFields = ['password', 'verify_url'])
    {
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = self::encrypt($data[$field]);
            }
        }
        
        return $data;
    }

    /**
     * 批量解密数组中的敏感字段
     *
     * @param array $data
     * @param array $sensitiveFields
     * @return array
     */
    public static function decryptArray($data, $sensitiveFields = ['password', 'verify_url'])
    {
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = self::decrypt($data[$field]);
            }
        }
        
        return $data;
    }
} 