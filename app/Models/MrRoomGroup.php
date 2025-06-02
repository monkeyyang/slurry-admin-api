<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MrRoomGroup extends Model
{
    use HasFactory;

    protected $table = 'mr_room_group';
    protected $connection = 'mysql_card';

    protected $fillable = [
        'uid',
        'room_ids',
        'name',
        'back_color'
    ];

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray(): array
    {
        return [
            'id' => (string)$this->id,
            'name' => $this->name,
            'code' => $this->back_color,
            'status' => 'active', // Since there's no status field in the table, we'll default to active
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
