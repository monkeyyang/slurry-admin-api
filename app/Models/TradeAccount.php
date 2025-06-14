<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class TradeAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account',
        'password',
        'api_url',
        'country',
        'status',
        'imported_by',
        'imported_by_user_id',
        'imported_by_nickname',
        'imported_at',
        'remark',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];

    /**
     * 关联国家信息
     */
    public function countryInfo()
    {
        return $this->belongsTo(Country::class, 'country', 'code');
    }

    /**
     * 获取解密后的密码
     *
     * @return string
     */
    public function getDecryptedPassword(): string
    {
        if (empty($this->password)) {
            return '';
        }

        try {
            // 检查是否有APP_KEY
            if (empty(config('app.key'))) {
                Log::warning('APP_KEY not set, treating stored password as plain text');
                return $this->password; // 如果没有APP_KEY，假设存储的是明文
            }

            return Crypt::decryptString($this->password);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt password: ' . $e->getMessage());
            // 优雅降级：如果解密失败，可能存储的是明文
            Log::warning('Password decryption failed, treating as plain text');
            return $this->password;
        }
    }

    /**
     * 设置加密密码
     *
     * @param string $password
     * @return void
     */
    public function setEncryptedPassword(string $password): void
    {
        if (empty($password)) {
            $this->password = '';
            return;
        }

        try {
            // 检查是否有APP_KEY
            if (empty(config('app.key'))) {
                Log::warning('APP_KEY not set, storing password in plain text. Please run: php artisan key:generate');
                $this->password = $password; // 如果没有APP_KEY，直接存储明文
                return;
            }

            $this->password = Crypt::encryptString($password);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt password: ' . $e->getMessage());
            // 优雅降级：如果加密失败，记录警告但继续存储明文
            Log::warning('Password encryption failed, storing in plain text for account');
            $this->password = $password;
        }
    }

    /**
     * 转换为API数组格式
     *
     * @return array
     */
    public function toApiArray(): array
    {
        return [
            'id' => (string)$this->id,
            'account' => $this->account,
            'country' => $this->country,
            'status' => $this->status,
            'importedBy' => $this->imported_by,
            'importedByUserId' => $this->imported_by_user_id ? (string)$this->imported_by_user_id : null,
            'importedByNickname' => $this->imported_by_nickname,
            'importedAt' => $this->imported_at ? $this->imported_at->format('Y-m-d H:i:s') : null,
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            'countryInfo' => $this->countryInfo ? $this->countryInfo->toApiArray() : null,
        ];
    }

    /**
     * 作用域：按国家筛选
     *
     * @param $query
     * @param string $country
     * @return mixed
     */
    public function scopeByCountry($query, string $country)
    {
        return $query->where('country', $country);
    }

    /**
     * 作用域：按状态筛选
     *
     * @param $query
     * @param string $status
     * @return mixed
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：按导入者筛选
     *
     * @param $query
     * @param string $importedBy
     * @return mixed
     */
    public function scopeByImportedBy($query, string $importedBy)
    {
        return $query->where('imported_by', 'like', "%{$importedBy}%");
    }

    /**
     * 作用域：按时间范围筛选
     *
     * @param $query
     * @param string $startTime
     * @param string $endTime
     * @return mixed
     */
    public function scopeByTimeRange($query, string $startTime = null, string $endTime = null)
    {
        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }
        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }
        return $query;
    }

    /**
     * 作用域：模糊搜索账号
     *
     * @param $query
     * @param string $account
     * @return mixed
     */
    public function scopeByAccount($query, string $account)
    {
        return $query->where('account', 'like', "%{$account}%");
    }
} 