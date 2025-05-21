<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItunesTradeTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Convert the model to a response array
     *
     * @return array
     */
    public function toResponseArray(): array
    {
        return [
            'id' => (string)$this->id,
            'name' => $this->name,
            'data' => $this->data,
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
} 