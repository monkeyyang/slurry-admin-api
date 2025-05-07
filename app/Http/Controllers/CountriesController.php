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
            'keyword' => 'nullable|string'
        ]);

        $data = $this->countriesService->getCountries($validated['keyword'] ?? '');

        return $this->jsonOk($data);
    }
}
