<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItunesTradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItunesTradeController extends Controller
{
    protected ItunesTradeService $itunesTradeService;

    public function __construct(ItunesTradeService $itunesTradeService)
    {
        $this->itunesTradeService = $itunesTradeService;
    }

    /**
     * 获取所有国家配置
     *
     * @return JsonResponse
     */
    public function getConfigs(): JsonResponse
    {
        try {
            $configs = $this->itunesTradeService->getAllConfigs();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $configs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个国家配置
     *
     * @param string $countryCode
     * @return JsonResponse
     */
    public function getConfig(string $countryCode): JsonResponse
    {
        try {
            $config = $this->itunesTradeService->getCountryConfig($countryCode);

            if (!$config) {
                return response()->json([
                    'code' => 404,
                    'message' => '未找到国家配置',
                    'data' => null,
                ]);
            }

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 保存国家配置
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveConfig(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $config = $this->itunesTradeService->saveConfig($data);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新国家配置
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateConfig(string $id, Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $config = $this->itunesTradeService->updateConfig((int)$id, $data);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 删除国家配置
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deleteConfig(string $id): JsonResponse
    {
        try {
            $result = $this->itunesTradeService->deleteConfig((int)$id);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取所有模板
     *
     * @return JsonResponse
     */
    public function getTemplates(): JsonResponse
    {
        try {
            $templates = $this->itunesTradeService->getAllTemplates();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $templates,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 保存模板
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveTemplate(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name');
            $data = $request->input('data');

            $template = $this->itunesTradeService->saveTemplate($name, $data);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $template,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 应用模板
     *
     * @param string $id
     * @return JsonResponse
     */
    public function applyTemplate(string $id): JsonResponse
    {
        try {
            $configs = $this->itunesTradeService->applyTemplate((int)$id);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $configs,
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
