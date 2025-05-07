<?php

namespace App\Http\Controllers;

use App\Services\CountriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountriesController extends Controller
{
    protected CountriesService $countriesService;

    public function __construct(CountriesService $services)
    {
        $this->countriesService = $services;
    }

    /**
     * 获取国家列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'nullable|string',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'pageNum' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'sortField' => 'nullable|string|in:id,name_zh,name_en,code',
            'sortOrder' => 'nullable|string|in:asc,desc',
        ]);

        $data = $this->countriesService->getCountries($validated);

        return $this->jsonOk($data);
    }

    public function disable(string $id): JsonResponse
    {
        try {
            $this->countriesService->disable($id);
            return $this->jsonOk([], '禁用成功');
        } catch (\Exception $e) {
            return $this->jsonError('禁用失败');
        }
    }

    public function enable(string $id): JsonResponse
    {
        try{
            $this->countriesService->enable($id);
            return $this->jsonOk([],'启用成功');
        } catch (\Exception $e) {
            return $this->jsonError('启用失败');
        }
    }


}
