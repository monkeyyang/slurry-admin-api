<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OperationLog extends Model
{
    use HasFactory;

    protected $table = 'operation_logs';

    protected $fillable = [
        'uid',
        'room_id',
        'wxid',
        'operation_type',
        'target_account',
        'result',
        'details',
        'user_agent',
        'ip_address'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }

    /**
     * 操作类型常量
     */
    const OPERATION_TYPES = [
        'search' => '搜索',
        'delete' => '删除',
        'copy' => '复制',
        'getVerifyCode' => '获取验证码',
        'edit' => '编辑',
        'create' => '创建',
        'import' => '导入',
        'export' => '导出',
        'password_verify' => '密码验证',
        'page_view' => '页面浏览'
    ];

    /**
     * 操作结果常量
     */
    const RESULT_TYPES = [
        'success' => '成功',
        'failed' => '失败',
        'password_error' => '密码错误'
    ];
}
