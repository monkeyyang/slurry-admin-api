<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MrRoomGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MrRoomGroupController extends Controller
{
    protected MrRoomGroupService $mrRoomGroupService;

    public function __construct(MrRoomGroupService $mrRoomGroupService)
    {
        $this->mrRoomGroupService = $mrRoomGroupService;
    }

    /**
     * Get paginated list of room groups
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getGroupsList(Request $request): JsonResponse
    {
        try {
            $params = $request->only(['pageSize', 'pageNum', 'status']);
            $result = $this->mrRoomGroupService->getGroupsList($params);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }
} 