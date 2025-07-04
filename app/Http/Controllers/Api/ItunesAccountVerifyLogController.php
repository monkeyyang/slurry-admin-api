<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ItunesAccountVerifyLog;

class ItunesAccountVerifyLogController extends Controller
{
    // 获取查码记录列表
    public function index(Request $request)
    {
        $query = ItunesAccountVerifyLog::with(['user', 'verifyAccount']);

        if ($request->account) $query->where('account', 'like', '%' . $request->account . '%');
        if ($request->account_id) $query->where('account_id', $request->account_id);
        if ($request->type) $query->where('type', $request->type);
        if ($request->uid) $query->where('uid', $request->uid);
        if ($request->room_id) $query->where('room_id', $request->room_id);
        if ($request->wxid) $query->where('wxid', $request->wxid);
        if ($request->startTime) $query->where('created_at', '>=', $request->startTime);
        if ($request->endTime) $query->where('created_at', '<=', $request->endTime);

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

    // 查码记录详情
    public function show($id)
    {
        $log = ItunesAccountVerifyLog::with(['user', 'verifyAccount'])->findOrFail($id);
        return response()->json(['code' => 0, 'message' => 'success', 'data' => $log]);
    }

    // 统计
    public function statistics(Request $request)
    {
        $query = ItunesAccountVerifyLog::query();
        if ($request->startTime) $query->where('created_at', '>=', $request->startTime);
        if ($request->endTime) $query->where('created_at', '<=', $request->endTime);

        $totalCount = $query->count();
        $copyCount = (clone $query)->where('type', 'copy')->count();
        $checkCodeCount = (clone $query)->where('type', 'check_code')->count();
        $todayCount = (clone $query)->whereDate('created_at', now()->toDateString())->count();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'totalCount' => $totalCount,
                'copyCount' => $copyCount,
                'checkCodeCount' => $checkCodeCount,
                'todayCount' => $todayCount,
            ]
        ]);
    }

    // 删除
    public function destroy($id)
    {
        ItunesAccountVerifyLog::destroy($id);
        return response()->json(['code' => 0, 'message' => 'success', 'data' => null]);
    }

    // 批量删除
    public function batchDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        ItunesAccountVerifyLog::whereIn('id', $ids)->delete();
        return response()->json(['code' => 0, 'message' => 'success', 'data' => null]);
    }
}
