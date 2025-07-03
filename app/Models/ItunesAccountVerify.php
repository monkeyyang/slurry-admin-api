<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItunesAccountVerify extends Model
{
    use SoftDeletes;

    protected $table = 'itunes_account_verify';

    protected $fillable = [
        'uid', 'account', 'password', 'verify_url'
    ];

    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }
}