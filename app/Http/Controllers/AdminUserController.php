<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    public function getAdminUserList(Request $request)
    {
        $page_index = intval($request->page_index);
        $page_size = intval($request->page_size);
        $id = $request->id;
        $username = $request->username;
        $phone_number = $request->phone_number;
        $status = $request->status;

        $sql = "(
            SELECT u.* FROM admin_users u where u.deleted = 0
        ) a";
        $query = DB::table(DB::raw($sql))
            ->select('id', 'username', 'nickname', 'email', 'phone_number',
                'gender', 'avatar', 'is_admin', 'remark', 'status', 'create_time');

        if (!is_null($id)) {
            $query->where('id', $id);
        }
        if (!is_null($username)) {
            $query->where('username', $username);
        }
        if (!is_null($phone_number)) {
            $query->where('phone_number', $phone_number);
        }
        if (!is_null($status)) {
            $query->where('status', $status);
        }

        $pages = $query
            ->orderBy($orderColumn ?? 'create_time', $orderType ?? 'desc')
            ->paginate($page_size, ['*'], 'page', $page_index);

        foreach ($pages->items() as &$user_info) {
            $user_info->role_ids = DB::table('admin_user_role')
                ->where('user_id', $user_info->id)
                ->pluck('role_id');
        }

        return $this->jsonOk([
            'total' => $pages->total(),
            'rows' => $pages->items(),
        ]);
    }

    public function create(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string',
            'password' => 'required|string',
            'status' => 'required|string',
        ]);
        $body_entity = $request->json()->all();

        $hashed_password = password_hash($body_entity['password'], PASSWORD_DEFAULT);

        $user_info = [
            'username' => $body_entity['username'],
            'nickname' => $body_entity['nickname'],
            'email' => $body_entity['email'] ?? null,
            'phone_number' => $body_entity['phone_number'] ?? null,
            'gender' => $body_entity['gender'] ?? 'UNKNOWN',
            'password' => $hashed_password,
            'remark' => $body_entity['remark'] ?? null,
            'status' => $body_entity['status'],
        ];

        DB::beginTransaction();
        try {
            $user_id = DB::table('admin_users')->insertGetId($user_info);

            $role_ids = $body_entity['role_ids'];
            foreach ($role_ids as $role_id) {
                DB::table('admin_user_role')->insert(['user_id' => $user_id, 'role_id' => $role_id]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();

            Log::error($e);
            return $this->jsonError('系统异常');
        }

        return $this->jsonOk([
            'id' => $user_id,
        ]);
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|int',
            'username' => 'required|string',
            'status' => 'required|string',
        ]);

        $body_entity = $request->json()->all();
        $user_id = $body_entity['id'];

        $user_info = [
            'username' => $body_entity['username'],
            'nickname' => $body_entity['nickname'],
            'email' => $body_entity['email'],
            'phone_number' => $body_entity['phone_number'],
            'gender' => $body_entity['gender'],
            'remark' => $body_entity['remark'],
            'status' => $body_entity['status'],
        ];

        DB::beginTransaction();
        try {
            DB::table('admin_users')
                ->where('id', $user_id)
                ->update($user_info);

            DB::table('admin_user_role')
                ->where('user_id', $user_id)
                ->delete();

            $role_ids = $body_entity['role_ids'];
            foreach ($role_ids as $role_id) {
                DB::table('admin_user_role')->insert(['user_id' => $user_id, 'role_id' => $role_id]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();

            Log::error($e);
            return $this->jsonError('系统异常');
        }

        return $this->jsonOk();
    }

    public function updateStatus(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|int',
            'status' => 'required|string|max:20',
        ]);

        $userId = $request->id;
        $status = $request->status;

        DB::table('admin_users')
            ->where('id', $userId)
            ->update([
                'status' => $status,
            ]);

        return $this->jsonOk();
    }

    //重置密码
    public function resetPassword(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|int',
            'password' => 'required|string|max:20',
        ]);

        $userId = $request->id;
        $password = $request->password;

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        DB::table('admin_users')
            ->where('id', $userId)
            ->update([
                'password' => $hashed_password,
            ]);

        return $this->jsonOk();
    }

    public function delete(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|int',
        ]);

        $user_id = $request->id;

        DB::table('admin_users')
            ->where('id', $user_id)
            ->update([
                'deleted' => 1,
            ]);

        DB::table('admin_user_role')
            ->where('user_id', $user_id)
            ->delete();

        return $this->jsonOk();
    }

    //获取自己的用户信息
    public function profile(Request $request)
    {
        $userId = $this->getAdminId();

        // 获取基本用户信息
        $userinfo = DB::table('admin_users')
            ->select('id', 'username', 'phone_number', 'gender', 'nickname', 'avatar', 'email', 'create_time', 'is_admin', 'status')
            ->where('id', $userId)
            ->first();

        if (!$userinfo) {
            return $this->jsonError('用户不存在');
        }

        // 获取用户角色ID列表
        $userinfo->role_ids = DB::table('admin_user_role')
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->toArray();

        // 获取用户角色详细信息
        $roles = DB::table('admin_roles')
            ->whereIn('id', $userinfo->role_ids)
            ->where('status', 'ENABLED') // 只获取启用状态的角色
            ->select('id', 'name', 'key', 'remark')
            ->get();

        // 将角色详细信息添加到用户信息中
        $userinfo->roles = $roles;

        // 添加角色代码列表，方便前端判断权限
        $userinfo->role_codes = $roles->pluck('key')->toArray();

        return $this->jsonOk($userinfo);
    }

    //修改自己的密码
    public function changePassword(Request $request)
    {
        $this->validate($request, [
            'old_password' => 'required|string|max:20',
            'new_password' => 'required|string|max:20',
        ]);

        $old_password = $request->old_password;
        $new_password = $request->new_password;

        $userId = $this->getAdminId();

        $userinfo = DB::table('admin_users')
            ->select('id', 'password')
            ->where('id', $userId)
            ->first();

        if (!password_verify($old_password, $userinfo->password)) {
            return $this->jsonError('旧密码错误');
        }

        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

        DB::table('admin_users')
            ->where('id', $userId)
            ->update([
                'password' => $hashed_new_password,
            ]);

        return $this->jsonOk();
    }
}
