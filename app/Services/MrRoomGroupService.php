<?php

namespace App\Services;

use App\Models\MrRoomGroup;
use Illuminate\Support\Facades\Log;

class MrRoomGroupService
{
    /**
     * Get paginated list of room groups
     *
     * @param array $params
     * @return array
     */
    public function getGroupsList(array $params): array
    {
        try {
            $query = MrRoomGroup::query();

            // Apply filters
            if (isset($params['status'])) {
                // Since we don't have a status field, we'll skip this filter
            }

            // Apply pagination
            $pageSize = $params['pageSize'] ?? 10;
            $pageNum = $params['pageNum'] ?? 1;

            $groups = $query->where('uid',19)->paginate($pageSize, ['*'], 'page', $pageNum);

            return [
                'data' => $groups->items() ? collect($groups->items())->map(function($group) {
                    return $group->toApiArray();
                }) : [],
                'total' => $groups->total(),
            ];
        } catch (\Exception $e) {
            Log::channel('wechat')->error('Failed to get room groups list: ' . $e->getMessage());
            throw $e;
        }
    }
}
