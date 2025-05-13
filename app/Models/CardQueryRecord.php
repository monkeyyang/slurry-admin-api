<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CardQueryRecord extends Model
{
    protected $fillable = [
        'card_code',
        'query_count',
        'first_query_at',
        'second_query_at',
        'next_query_at',
        'is_valid',
        'response_data',
        'is_completed'
    ];
    
    protected $casts = [
        'first_query_at' => 'datetime',
        'second_query_at' => 'datetime',
        'next_query_at' => 'datetime',
        'is_valid' => 'boolean',
        'is_completed' => 'boolean',
    ];
    
    /**
     * 获取可查询的卡密
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getQueryableCards()
    {
        return self::where('is_completed', false)
                 ->where(function($query) {
                     $query->whereNull('next_query_at')
                           ->orWhere('next_query_at', '<=', now());
                 })
                 ->get();
    }
    
    /**
     * 根据规则计算下次查询时间
     * 
     * @param CardQueryRule $rule
     * @return void
     */
    public function calculateNextQueryTime(CardQueryRule $rule)
    {
        if ($this->query_count == 0) {
            // 首次查询
            $this->next_query_at = now()->addMinutes($rule->first_interval);
        } else if ($this->query_count == 1) {
            // 第二次查询
            $this->next_query_at = now()->addMinutes($rule->first_interval + $rule->second_interval);
        } else {
            // 已查询两次，不再查询
            $this->is_completed = true;
            $this->next_query_at = null;
        }
        
        $this->save();
    }
} 