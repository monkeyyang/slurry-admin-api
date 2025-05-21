<?php

namespace App\Services;

use App\Models\ItunesTradeConfig;
use App\Models\ItunesTradeTemplate;
use Illuminate\Support\Facades\Log;

class ItunesTradeService
{
    /**
     * Get all country configurations
     *
     * @return array
     */
    public function getAllConfigs(): array
    {
        $configs = ItunesTradeConfig::all();
        return $configs->map->toResponseArray()->all();
    }

    /**
     * Get configuration for a specific country
     *
     * @param string $countryCode
     * @return array|null
     */
    public function getCountryConfig(string $countryCode): ?array
    {
        $config = ItunesTradeConfig::where('country', $countryCode)->first();
        return $config ? $config->toResponseArray() : null;
    }

    /**
     * Save a new country configuration
     *
     * @param array $data
     * @return array
     */
    public function saveConfig(array $data): array
    {
        try {
            $config = ItunesTradeConfig::createOrUpdateFromRequest($data);
            return $config->toResponseArray();
        } catch (\Exception $e) {
            Log::error('Failed to save iTunes trade config: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing country configuration
     *
     * @param int $id
     * @param array $data
     * @return array
     */
    public function updateConfig(int $id, array $data): array
    {
        try {
            $config = ItunesTradeConfig::findOrFail($id);
            
            $configData = [
                'fastCard' => $data['fastCard'] ?? [],
                'slowCard' => $data['slowCard'] ?? [],
                'secondaryRateEnabled' => $data['secondaryRateEnabled'] ?? false,
                'secondaryRate' => $data['secondaryRate'] ?? 0,
                'secondaryMinAmount' => $data['secondaryMinAmount'] ?? 0,
                'secondaryRemark' => $data['secondaryRemark'] ?? '',
                'commonRemarks' => $data['commonRemarks'] ?? [],
            ];
            
            $config->update([
                'country' => $data['country'],
                'country_name' => $data['countryName'],
                'config' => $configData,
            ]);
            
            return $config->toResponseArray();
        } catch (\Exception $e) {
            Log::error('Failed to update iTunes trade config: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a country configuration
     *
     * @param int $id
     * @return bool
     */
    public function deleteConfig(int $id): bool
    {
        try {
            $config = ItunesTradeConfig::findOrFail($id);
            return $config->delete();
        } catch (\Exception $e) {
            Log::error('Failed to delete iTunes trade config: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all templates
     *
     * @return array
     */
    public function getAllTemplates(): array
    {
        $templates = ItunesTradeTemplate::all();
        return $templates->map->toResponseArray()->all();
    }

    /**
     * Save a new template
     *
     * @param string $name
     * @param array $data
     * @return array
     */
    public function saveTemplate(string $name, array $data): array
    {
        try {
            $template = ItunesTradeTemplate::create([
                'name' => $name,
                'data' => $data,
            ]);
            
            return $template->toResponseArray();
        } catch (\Exception $e) {
            Log::error('Failed to save iTunes trade template: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply a template to existing configurations
     *
     * @param int $id
     * @return array
     */
    public function applyTemplate(int $id): array
    {
        try {
            $template = ItunesTradeTemplate::findOrFail($id);
            $configs = [];
            
            foreach ($template->data as $configData) {
                $config = ItunesTradeConfig::createOrUpdateFromRequest($configData);
                $configs[] = $config->toResponseArray();
            }
            
            return $configs;
        } catch (\Exception $e) {
            Log::error('Failed to apply iTunes trade template: ' . $e->getMessage());
            throw $e;
        }
    }
} 