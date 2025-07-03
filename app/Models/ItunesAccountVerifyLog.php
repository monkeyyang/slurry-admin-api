<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItunesAccountVerifyLog extends Model
{
    protected $table = 'itunes_account_verify_logs';

    protected $fillable = [
        'uid', 'account_id', 'account', 'type', 'commands', 'room_id', 'wxid', 'verify_code', 'msg'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }

    public function verifyAccount()
    {
        return $this->belongsTo(ItunesAccountVerify::class, 'account_id', 'id');
    }
}