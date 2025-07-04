<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\EncryptionService;

class ItunesAccountVerify extends Model
{
    use SoftDeletes;

    protected $table = 'itunes_account_verify';

    protected $fillable = [
        'uid', 'account', 'password', 'verify_url'
    ];

    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }

    /**
     * 保存前加密敏感字段
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if (isset($model->password) && !empty($model->password)) {
                $model->password = EncryptionService::encrypt($model->password);
            }
            if (isset($model->verify_url) && !empty($model->verify_url)) {
                $model->verify_url = EncryptionService::encrypt($model->verify_url);
            }
        });

        static::retrieved(function ($model) {
            if (isset($model->password) && !empty($model->password)) {
                $model->password = EncryptionService::decrypt($model->password);
            }
            if (isset($model->verify_url) && !empty($model->verify_url)) {
                $model->verify_url = EncryptionService::decrypt($model->verify_url);
            }
        });
    }

    /**
     * 获取加密的密码（用于API返回）
     */
    public function getEncryptedPasswordAttribute()
    {
        return EncryptionService::encrypt($this->password);
    }

    /**
     * 获取加密的验证码地址（用于API返回）
     */
    public function getEncryptedVerifyUrlAttribute()
    {
        return EncryptionService::encrypt($this->verify_url);
    }
}