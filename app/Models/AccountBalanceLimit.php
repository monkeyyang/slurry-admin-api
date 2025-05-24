<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountBalanceLimit extends Model
{
    use HasFactory;
    
    /**
     * 状态常量
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    
    /**
     * 允许批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'account',
        'balance_limit',
        'current_balance',
        'status',
        'last_redemption_at',
        'last_checked_at',
    ];
    
    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'balance_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'last_redemption_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];
    
    /**
     * 检查账号是否可用于兑换指定金额
     *
     * @param float $amount 要兑换的金额
     * @return bool 是否可用
     */
    public function canRedeemAmount(float $amount): bool
    {
        // 检查状态是否激活
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        
        // 检查是否超过余额上限
        return ($this->current_balance + $amount) <= $this->balance_limit;
    }
    
    /**
     * 更新当前余额
     *
     * @param float $amount 要增加的金额
     * @return $this
     */
    public function addBalance(float $amount)
    {
        $this->current_balance += $amount;
        $this->last_redemption_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * 更新检查时间
     *
     * @return $this
     */
    public function updateCheckedTime()
    {
        $this->last_checked_at = now();
        $this->save();
        
        return $this;
    }
    
    /**
     * 转换为API数组
     *
     * @return array
     */
    public function toApiArray(): array
    {
        return [
            'id' => (string)$this->id,
            'account' => $this->account,
            'balanceLimit' => $this->balance_limit,
            'currentBalance' => $this->current_balance,
            'status' => $this->status,
            'lastRedemptionAt' => $this->last_redemption_at ? $this->last_redemption_at->toISOString() : null,
            'lastCheckedAt' => $this->last_checked_at ? $this->last_checked_at->toISOString() : null,
            'remainingBalance' => $this->balance_limit - $this->current_balance,
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
} 