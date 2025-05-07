<?php

namespace App\Services;

use App\Models\Countries;
use Illuminate\Pagination\LengthAwarePaginator;

class CountriesService
{

    /**
     * 获取国家列表
     *
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function getCountries(array $params): LengthAwarePaginator
    {
        // 创建查询构建器
        $query = Countries::query()->where('status', 1);

        // 关键词搜索
        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('name_zh', 'like', '%' . $keyword . '%')
                  ->orWhere('name_en', 'like', '%' . $keyword . '%')
                  ->orWhere('code', 'like', '%' . $keyword . '%')
                  ->orWhere('code2', 'like', '%' . $keyword . '%');
            });
        }

        // 排序
        $sortField = $params['sortField'] ?? 'id';
        $sortOrder = $params['sortOrder'] ?? 'asc';

        // 确保排序字段有效
        $validSortFields = ['id', 'name_zh', 'name_en', 'code', 'code2'];
        if (!in_array($sortField, $validSortFields)) {
            $sortField = 'id';
        }

        $query->orderBy($sortField, $sortOrder);

        // 支持pageNum替代page参数
        $page = $params['page'] ?? $params['pageNum'] ?? 1;

        return $query->paginate(
            $params['pageSize'] ?? 10,
            ['*'],
            'page',
            $page
        );

//        // 分页
//        $page = $params['page'] ?? $params['pageNum'] ?? 1;
//        $pageSize = $params['pageSize'] ?? 10;
//
//        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);
        //        $sql = $query->toSql();                // 获取 SQL 模板，如：select * from countries where ...
        //        $bindings = $query->getBindings();    // 获取绑定参数
        //
        //        dd(vsprintf(str_replace('?', '%s', $sql), $bindings));
        // 构建符合接口规范的返回数据
//        return [
//            'list' => $paginator->items(),
//            'total' => $paginator->total(),
//            'page' => $paginator->currentPage(),
//            'pageSize' => $paginator->perPage(),
//            'hasMore' => $paginator->hasMorePages()
//        ];
    }

    public function disable(int $id): bool
    {
        return Countries::disable($id);
    }

    public function enable(int $id): bool
    {
        return Countries::enable($id);
    }
}
