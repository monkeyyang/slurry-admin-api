<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItunesTradeConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'country',
        'country_name',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    /**
     * Convert the model instance to an array that matches the frontend interface.
     *
     * @return array
     */
    public function toResponseArray(): array
    {
        $config = $this->config;
        
        return [
            'id' => (string)$this->id,
            'country' => $this->country,
            'countryName' => $this->country_name,
            'fastCard' => $config['fastCard'] ?? [
                'image' => [
                    'enabled' => false,
                    'rate' => 0,
                    'minAmount' => 0,
                    'maxAmount' => 0,
                    'amountConstraint' => 'none',
                    'multipleBase' => 0,
                    'remarks' => []
                ],
                'code' => [
                    'enabled' => false,
                    'rate' => 0,
                    'minAmount' => 0,
                    'maxAmount' => 0,
                    'amountConstraint' => 'none',
                    'multipleBase' => 0,
                    'remarks' => []
                ]
            ],
            'slowCard' => $config['slowCard'] ?? [
                'image' => [
                    'enabled' => false,
                    'rate' => 0,
                    'minAmount' => 0,
                    'maxAmount' => 0,
                    'amountConstraint' => 'none',
                    'multipleBase' => 0,
                    'remarks' => []
                ],
                'code' => [
                    'enabled' => false,
                    'rate' => 0,
                    'minAmount' => 0,
                    'maxAmount' => 0,
                    'amountConstraint' => 'none',
                    'multipleBase' => 0,
                    'remarks' => []
                ]
            ],
            'secondaryRateEnabled' => $config['secondaryRateEnabled'] ?? false,
            'secondaryRate' => $config['secondaryRate'] ?? 0,
            'secondaryMinAmount' => $config['secondaryMinAmount'] ?? 0,
            'secondaryRemark' => $config['secondaryRemark'] ?? '',
            'commonRemarks' => $config['commonRemarks'] ?? [],
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }

    /**
     * Create or update configuration from frontend data
     *
     * @param array $data
     * @return self
     */
    public static function createOrUpdateFromRequest(array $data): self
    {
        $config = [
            'fastCard' => $data['fastCard'] ?? [],
            'slowCard' => $data['slowCard'] ?? [],
            'secondaryRateEnabled' => $data['secondaryRateEnabled'] ?? false,
            'secondaryRate' => $data['secondaryRate'] ?? 0,
            'secondaryMinAmount' => $data['secondaryMinAmount'] ?? 0,
            'secondaryRemark' => $data['secondaryRemark'] ?? '',
            'commonRemarks' => $data['commonRemarks'] ?? [],
        ];

        return self::updateOrCreate(
            ['country' => $data['country']],
            [
                'country_name' => $data['countryName'],
                'config' => $config,
            ]
        );
    }
} 