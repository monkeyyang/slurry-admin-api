<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationCodeController extends Controller
{
    /**
     * 获取邀请码列表
     */
    public function index(Request $request)
    {
        $query = DB::table('invitation_codes')
            ->select('invitation_codes.*', 'creator.username as creator_name', 'user.username as user_name')
            ->leftJoin('admin_users as creator', 'creator.id', '=', 'invitation_codes.created_by')
            ->leftJoin('admin_users as user', 'user.id', '=', 'invitation_codes.used_by')
            ->orderBy('invitation_codes.id', 'desc');

        // 邀请码筛选
        if ($request->filled('code')) {
            $query->where('invitation_codes.code', 'like', '%' . $request->code . '%');
        }

        // 状态筛选
        if ($request->filled('status')) {
            $query->where('invitation_codes.status', $request->status);
        }

        // 创建人筛选
        if ($request->filled('createdBy')) {
            $query->where('invitation_codes.created_by', $request->createdBy);
        }

        // 使用人筛选
        if ($request->filled('usedBy')) {
            $query->where('invitation_codes.used_by', $request->usedBy);
        }

        // 时间范围筛选
        if ($request->filled('startTime') && $request->filled('endTime')) {
            $query->whereBetween('invitation_codes.created_at', [$request->startTime, $request->endTime]);
        } else if ($request->filled('startTime')) {
            $query->where('invitation_codes.created_at', '>=', $request->startTime);
        } else if ($request->filled('endTime')) {
            $query->where('invitation_codes.created_at', '<=', $request->endTime);
        }

        $data = $query->paginate($request->input('pageSize', 10));
        return $this->jsonOk($data);
    }

    /**
     * 生成邀请码
     */
    public function generate(Request $request)
    {
        $this->validate($request, [
            'count' => 'required|integer|min:1|max:100',
        ]);

        $count = $request->input('count');
        $userId = $request->user()->id;
        $codes = [];

        DB::beginTransaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                $code = strtoupper(Str::random(8));
                DB::table('invitation_codes')->insert([
                    'code' => $code,
                    'status' => 0,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $codes[] = $code;
            }
            DB::commit();
            return $this->jsonOk(['codes' => $codes]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('生成邀请码失败：' . $e->getMessage());
        }
    }

    /**
     * 删除未使用的邀请码
     */
    public function destroy($id)
    {
        $code = DB::table('invitation_codes')->where('id', $id)->first();
        
        if (!$code) {
            return $this->jsonError('邀请码不存在');
        }

        if ($code->status == 1) {
            return $this->jsonError('已使用的邀请码不能删除');
        }

        DB::table('invitation_codes')->where('id', $id)->delete();
        return $this->jsonOk();
    }

    /**
     * 新增邀请码
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'code' => 'required|string|unique:invitation_codes,code',
            'expireTime' => 'nullable|date',
            'remark' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;
        
        try {
            DB::table('invitation_codes')->insert([
                'code' => $request->code,
                'status' => 0,
                'expired_at' => $request->expireTime,
                'remark' => $request->remark,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            return $this->jsonOk([], '邀请码创建成功');
        } catch (\Exception $e) {
            return $this->jsonError('创建邀请码失败：' . $e->getMessage());
        }
    }
} 