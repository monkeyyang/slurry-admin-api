<?php

namespace App\Services;

use App\Models\Countries;

class CountriesService
{
    /**
     * 获取国家列表
     *
     * @param string $input
     * @return array
     */
    public function getCountries(string $input = ""): array
    {
        // 只查询有效数据
        $query = Countries::enabled();

        if (!empty($input)) {
            $query->where(function ($q) use ($input) {
                $q->where('name_zh', 'like', '%' . $input . '%')
                    ->orWhere('name_en', 'like', '%' . $input . '%')
                    ->orWhere('code', 'like', '%' . $input . '%')
                    ->orWhere('code2', 'like', '%' . $input . '%');
            });
        }

        return $query->get()->toArray();
    }
}
