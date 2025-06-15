<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class ItunesTradeAccount extends Model
{
    use HasFactory;

    protected $table = 'itunes_trade_accounts';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    // 状态常量
    const STATUS_COMPLETED = 'completed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_WAITING = 'waiting';

    // 登录状态常量
    const STATUS_LOGIN_ACTIVE = 'valid';
    const STATUS_LOGIN_FAILED = 'failed';
    const STATUS_LOGGED_OUT = 'logout';

    protected $fillable = [
        'account',
        'password',
        'api_url',
        'amount',
        'country_code',
        'status',
        'login_status',
        'current_plan_day',
        'plan_id',
        'room_id',
        'uid',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'room_id' => 'integer',
        'current_plan_day' => 'integer',
        'uid' => 'integer',
    ];

    protected $hidden = [
        'password',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    /**
     * 设置密码时自动加密
     */
    public function setPasswordAttribute($value): void
    {
        if (!empty($value)) {
            $this->setEncryptedPassword($value);
        }
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
            $this->attributes['password'] = '';
            return;
        }

        try {
            // 检查是否有APP_KEY
            if (empty(config('app.key'))) {
                Log::warning('APP_KEY not set, storing password in plain text. Please run: php artisan key:generate');
                $this->attributes['password'] = $password; // 如果没有APP_KEY，直接存储明文
                return;
            }

            $this->attributes['password'] = Crypt::encryptString($password);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt password: ' . $e->getMessage());
            // 优雅降级：如果加密失败，记录警告但继续存储明文
            Log::warning('Password encryption failed, storing in plain text for account');
            $this->attributes['password'] = $password;
        }
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_PROCESSING => '执行中',
            self::STATUS_WAITING => '等待中',
            default => '未知',
        };
    }

    /**
     * 获取登录状态文本
     */
    public function getLoginStatusTextAttribute(): ?string
    {
        if (is_null($this->login_status)) {
            return null;
        }

        return match($this->login_status) {
            self::STATUS_LOGIN_ACTIVE => '有效',
            self::STATUS_LOGIN_FAILED => '登录失败',
            default => '未登录',
        };
    }

    /**
     * 关联计划
     */
    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ItunesTradePlan::class, 'plan_id');
    }

    /**
     * 关联国家
     */
    public function country(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Countries::class, 'country_code', 'code');
    }

    /**
     * 关联兑换日志
     */
    public function exchangeLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItunesTradeAccountLog::class, 'account_id');
    }

    /**
     * 获取计划信息（安全获取）
     */
    public function getPlanInfo()
    {
        if ($this->relationLoaded('plan')) {
            return $this->plan;
        }

        if (empty($this->plan_id)) {
            return null;
        }

        return ItunesTradePlan::where('id', $this->plan_id)->first();
    }

    /**
     * 获取国家信息（安全获取）
     */
    public function getCountryInfo()
    {
        if ($this->relationLoaded('country')) {
            return $this->country;
        }

        if (empty($this->country_code)) {
            return null;
        }

        return Countries::where('code', $this->country_code)->first();
    }

    /**
     * 获取用户信息（安全获取）
     */
    public function getUserInfo()
    {
        if ($this->relationLoaded('user')) {
            return $this->user;
        }

        if (empty($this->uid)) {
            return null;
        }

        return \App\Models\User::where('id', $this->uid)->first();
    }

    /**
     * 获取每日完成情况
     */
    public function getDailyCompletions()
    {
        $planInfo = $this->getPlanInfo();
        if (!$planInfo) {
            return [];
        }

        $logs = $this->exchangeLogs()
                     ->where('plan_id', $this->plan_id)
                     ->orderBy('day')
                     ->get();

        $completions = [];
        for ($day = 1; $day <= $planInfo->plan_days; $day++) {
            $log = $logs->where('day', $day)->first();
            $amount = isset($planInfo->daily_amounts[$day - 1]) ? $planInfo->daily_amounts[$day - 1] : 0;

            if ($log) {
                $completions[] = [
                    'day' => $day,
                    'amount' => $amount,
                    'status' => $log->status === 'success' ? 'complete' :
                               ($log->status === 'pending' ? 'processing' : 'waiting'),
                    'time' => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : null,
                ];
            } else {
                $status = $day <= ($this->current_plan_day ?? 0) ? 'processing' : 'waiting';
                $completions[] = [
                    'day' => $day,
                    'amount' => $amount,
                    'status' => $status,
                    'time' => null,
                ];
            }
        }

        return $completions;
    }

    /**
     * 转换为API数组格式
     */
    public function toApiArray(): array
    {
        $user = $this->user;
        $plan = $this->plan;
        $country = $this->country;
        return [
            'id' => (string) $this->id,
            'account' => $this->account,
            'apiUrl' => $this->api_url,
            'country_code' => $this->country_code,
            'amount' => $this->amount,
            'status' => $this->status,
            'loginStatus' => $this->login_status,
            'currentPlanDay' => $this->current_plan_day,
            'planId' => $this->plan_id ? (string) $this->plan_id : null,
            'completedDays' => $this->getDailyCompletions(),
            'user' => $this->user ? [
                'nickname' => $this->user->nickname
            ] : null,
            'country' => $this->country ? [
                'id' => $this->country->id,
                'code' => $this->country->code,
                'name_zh' => $this->country->name_zh,
                'name_en' => $this->country->name_en
            ] : null,
            'plan' => $this->plan,
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * 转换为API数组格式（包含解密密码，仅用于特殊场景）
     */
    public function toApiArrayWithPassword(): array
    {
        $data = $this->toApiArray();
        $data['password'] = $this->getDecryptedPassword();
        return $data;
    }

    /**
     * 转换为详情API数组格式
     */
    public function toDetailApiArray(): array
    {
        $data = $this->toApiArray();

        $planInfo = $this->getPlanInfo();
        if ($planInfo) {
            $data['plan'] = [
                'id' => (string) $planInfo->id,
                'name' => $planInfo->name,
                'planDays' => $planInfo->plan_days,
                'dailyAmounts' => $planInfo->daily_amounts,
                'totalAmount' => (float) $planInfo->total_amount,
                'status' => $planInfo->status,
            ];
        }

        // 获取兑换日志
        $logs = $this->exchangeLogs()->orderBy('created_at', 'desc')->get();
        $data['exchangeLogs'] = $logs->map(function ($log) {
            return [
                'id' => (string) $log->id,
                'accountId' => (string) $log->account_id,
                'planId' => (string) $log->plan_id,
                'day' => $log->day,
                'amount' => (float) $log->amount,
                'status' => $log->status,
                'exchangeTime' => $log->exchange_time ? $log->exchange_time->format('Y-m-d H:i:s') : null,
                'errorMessage' => $log->error_message,
                'createdAt' => $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : null,
            ];
        })->toArray();

        return $data;
    }

    /**
     * 作用域：按账号筛选
     */
    public function scopeByAccount($query, string $account)
    {
        return $query->where('account', 'like', "%{$account}%");
    }

    /**
     * 作用域：按国家筛选
     */
    public function scopeByCountry($query, string $country)
    {
        return $query->where('country_code', $country);
    }

    /**
     * 作用域：按状态筛选
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：按登录状态筛选
     */
    public function scopeByLoginStatus($query, string $loginStatus)
    {
        return $query->where('login_status', $loginStatus);
    }

    /**
     * 作用域：按用户筛选
     */
    public function scopeByUser($query, int $uid)
    {
        return $query->where('uid', $uid);
    }

    /**
     * 作用域：按时间范围筛选
     */
    public function scopeByTimeRange($query, string $startTime, string $endTime)
    {
        return $query->whereBetween('created_at', [$startTime, $endTime]);
    }

    /**
     * 作用域：按计划ID筛选
     */
    public function scopeByPlan($query, int $planId)
    {
        return $query->where('plan_id', $planId);
    }

    /**
     * 作用域：按群聊ID筛选
     */
    public function scopeByRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }
}
