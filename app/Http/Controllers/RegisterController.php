<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    /**
     * 用户注册
     */
    public function register(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string|max:32|unique:admin_users,username',
            'password' => 'required|string|min:6|max:20',
            'password_confirmation' => 'required|same:password',
            'email' => 'nullable|email|max:32|unique:admin_users,email',
            'invitation_code' => 'required|string',
        ]);

        // 验证邀请码
        $invitationCode = DB::table('invitation_codes')
            ->where('code', $request->invitation_code)
            ->first();

        if (!$invitationCode) {
            return $this->jsonError('邀请码不存在');
        }

        if ($invitationCode->status == 1) {
            return $this->jsonError('邀请码已被使用');
        }

        // 检查邀请码是否过期
        if ($invitationCode->expired_at && now()->gt($invitationCode->expired_at)) {
            return $this->jsonError('邀请码已过期');
        }

        // 获取运营角色ID
        $operationRoleId = DB::table('admin_roles')
            ->where('key', 'operating') // 使用正确的字段名 'key'
            ->value('id');

        if (!$operationRoleId) {
            return $this->jsonError('系统错误：未找到运营角色');
        }

        DB::beginTransaction();
        try {
            // 创建用户
            $userId = DB::table('admin_users')->insertGetId([
                'username' => $request->username,
                'nickname' => $request->username, // 默认昵称与用户名相同
                'email' => $request->email,
                'password' => password_hash($request->password, PASSWORD_DEFAULT),
                'status' => '1', // 默认启用
                'is_admin' => 0, // 非管理员
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => 0, // 未删除
            ]);

            // 分配运营角色
            DB::table('admin_user_role')->insert([
                'user_id' => $userId,
                'role_id' => $operationRoleId
            ]);

            // 更新邀请码状态
            DB::table('invitation_codes')
                ->where('id', $invitationCode->id)
                ->update([
                    'status' => 1,
                    'used_by' => $userId,
                    'used_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();
            return $this->jsonOk([], '注册成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('注册失败：' . $e->getMessage());
        }
    }
} 