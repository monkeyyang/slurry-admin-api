<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoExecutionSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'execution_interval',
        'max_concurrent_plans',
        'log_level',
        'last_execution_time',
        'next_execution_time',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_execution_time' => 'datetime',
        'next_execution_time' => 'datetime',
    ];

    /**
     * Get the current settings
     *
     * @return self
     */
    public static function getSettings()
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'enabled' => true,
                'execution_interval' => 30,
                'max_concurrent_plans' => 5,
                'log_level' => 'info',
            ]
        );
    }

    /**
     * Update execution times
     *
     * @return void
     */
    public function updateExecutionTimes()
    {
        $this->last_execution_time = now();
        $this->next_execution_time = now()->addMinutes($this->execution_interval);
        $this->save();
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        return [
            'enabled' => $this->enabled,
            'executionInterval' => $this->execution_interval,
            'maxConcurrentPlans' => $this->max_concurrent_plans,
            'logLevel' => $this->log_level,
            'lastExecutionTime' => $this->last_execution_time ? $this->last_execution_time->toISOString() : null,
            'nextExecutionTime' => $this->next_execution_time ? $this->next_execution_time->toISOString() : null,
        ];
    }
} 