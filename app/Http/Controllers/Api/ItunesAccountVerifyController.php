<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ItunesAccountVerify;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ItunesAccountVerifyController extends Controller
{
    // 获取账号列表
    public function index(Request $request)
    {
        $query = ItunesAccountVerify::with('user');

        if ($request->account) {
            $query->where('account', 'like', '%' . $request->account . '%');
        }
        if ($request->uid) {
            $query->where('uid', $request->uid);
        }

        $pageSize = $request->input('pageSize', 10);
        $pageNum = $request->input('pageNum', 1);

        $total = $query->count();
        $data = $query->forPage($pageNum, $pageSize)->get();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'data' => $data,
                'total' => $total,
                'pageNum' => (int)$pageNum,
                'pageSize' => (int)$pageSize,
            ]
        ]);
    }

    // 账号详情
    public function show($id)
    {
        $account = ItunesAccountVerify::with('user')->findOrFail($id);
        return response()->json(['code' => 0, 'message' => 'success', 'data' => $account]);
    }

    // 创建账号
    public function store(Request $request)
    {
        $data = $request->only(['uid', 'account', 'password', 'verify_url']);
        $account = ItunesAccountVerify::create($data);
        return response()->json(['code' => 0, 'message' => 'success', 'data' => $account]);
    }

    // 更新账号
    public function update($id, Request $request)
    {
        $account = ItunesAccountVerify::findOrFail($id);
        $account->update($request->only(['uid', 'account', 'password', 'verify_url']));
        return response()->json(['code' => 0, 'message' => 'success', 'data' => $account]);
    }

    // 删除账号
    public function destroy($id)
    {
        ItunesAccountVerify::destroy($id);
        return response()->json(['code' => 0, 'message' => 'success', 'data' => null]);
    }

    // 批量删除
    public function batchDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        ItunesAccountVerify::whereIn('id', $ids)->delete();
        return response()->json(['code' => 0, 'message' => 'success', 'data' => null]);
    }

    // 批量导入
    public function batchImport(Request $request)
    {
        $accounts = $request->input('accounts', []);
        $success = [];
        $fail = [];
        $duplicate = [];
        $restored = 0;
        $created = 0;
        $updated = 0;

        foreach ($accounts as $item) {
            $exist = ItunesAccountVerify::withTrashed()->where('account', $item['account'])->first();
            if ($exist) {
                if ($exist->trashed()) {
                    $exist->restore();
                    $exist->update($item);
                    $restored++;
                } else {
                    $exist->update($item);
                    $updated++;
                }
                $duplicate[] = $item['account'];
                $success[] = $exist;
            } else {
                $acc = ItunesAccountVerify::create($item);
                $created++;
                $success[] = $acc;
            }
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'successCount' => count($success),
                'failCount' => count($fail),
                'duplicateAccounts' => $duplicate,
                'accounts' => $success,
                'restoredCount' => $restored,
                'createdCount' => $created,
                'updatedCount' => $updated,
            ]
        ]);
    }

    // 复制账号密码
    public function copyAccount($id)
    {
        $account = ItunesAccountVerify::findOrFail($id);
        // 这里可以记录日志
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'account' => $account->account,
                'password' => $account->password,
            ]
        ]);
    }

    // 获取验证码
    public function getVerifyCode($id, Request $request)
    {
        $account = ItunesAccountVerify::findOrFail($id);
        // 这里应实现验证码获取逻辑
        $verify_code = '123456'; // 示例
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'verify_code' => $verify_code,
            ]
        ]);
    }
}