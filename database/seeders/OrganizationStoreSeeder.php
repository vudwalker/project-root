<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Seeder;

class OrganizationStoreSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->withTrashed()->updateOrCreate(
            ['code' => 'sample-company'],
            [
                'name' => 'サンプル運営会社',
                'is_active' => true,
                'deleted_at' => null,
            ],
        );

        $stores = [
            'daianji' => ['name' => '大安寺', 'order' => 10],
            'noda' => ['name' => '野田', 'order' => 20],
            'saidaiji' => ['name' => '西大寺', 'order' => 30],
            'okayama-tomida' => ['name' => '岡山富田', 'order' => 40],
        ];

        foreach ($stores as $code => $attributes) {
            Store::query()->withTrashed()->updateOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                ],
                [
                    'name' => $attributes['name'],
                    'display_order' => $attributes['order'],
                    'staffing_check_mode' => 'disabled',
                    'required_staff_count' => null,
                    'deleted_at' => null,
                ],
            );
        }
    }
}
