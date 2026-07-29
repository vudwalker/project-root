<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $organization = Organization::query()
                ->where('code', 'sample-company')
                ->firstOrFail();
            $stores = Store::query()
                ->where('organization_id', $organization->getKey())
                ->get()
                ->keyBy('code');

            $roles = collect([
                'staff' => 'スタッフ',
                'shift_manager' => 'シフト管理者',
                'system_admin' => 'システム管理者',
            ])->map(
                fn (string $name, string $code): Role => Role::query()->updateOrCreate(
                    ['code' => $code],
                    ['name' => $name],
                ),
            );

            $users = [
                'admin' => [
                    'name' => 'システム管理者',
                    'email' => 'admin@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff', 'system_admin'],
                ],
                'manager' => [
                    'name' => 'シフト管理者',
                    'email' => 'manager@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff', 'shift_manager'],
                ],
                'chikazawa' => [
                    'name' => '近澤幸次',
                    'email' => 'staff@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff'],
                ],
                'otsuki' => [
                    'name' => '大月敦弘',
                    'email' => 'otsuki@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff'],
                ],
                'fujimoto' => [
                    'name' => '藤本保子',
                    'email' => 'fujimoto@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff'],
                ],
                'motoyama' => [
                    'name' => '本山宏明',
                    'email' => 'motoyama@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff'],
                ],
                'oai' => [
                    'name' => '小合達也',
                    'email' => 'oai@example.com',
                    'primary_store' => 'daianji',
                    'roles' => ['staff'],
                ],
                'miyake' => [
                    'name' => '三宅由幸',
                    'email' => 'miyake@example.com',
                    'primary_store' => 'noda',
                    'roles' => ['staff'],
                ],
                'morinaga' => [
                    'name' => '森永俊巳',
                    'email' => 'morinaga@example.com',
                    'primary_store' => 'noda',
                    'roles' => ['staff'],
                ],
                'kawamoto' => [
                    'name' => '河本健二',
                    'email' => 'kawamoto@example.com',
                    'primary_store' => 'noda',
                    'roles' => ['staff'],
                ],
                'shimizu' => [
                    'name' => '清水輝夫',
                    'email' => 'shimizu@example.com',
                    'primary_store' => 'noda',
                    'roles' => ['staff'],
                ],
            ];

            $models = [];

            foreach ($users as $key => $attributes) {
                $primaryStore = $stores->get($attributes['primary_store']);

                $models[$key] = User::query()->updateOrCreate(
                    ['email' => $attributes['email']],
                    [
                        'organization_id' => $organization->getKey(),
                        'primary_store_id' => $primaryStore->getKey(),
                        'name' => $attributes['name'],
                        'password' => Hash::make('password'),
                        'status' => 'active',
                    ],
                );

                $roleIds = collect($attributes['roles'])
                    ->map(fn (string $code): int => (int) $roles->get($code)->getKey())
                    ->all();
                $models[$key]->roles()->sync($roleIds);
            }

            $memberships = [
                'daianji' => [
                    'otsuki' => 1,
                    'fujimoto' => 2,
                    'motoyama' => 3,
                    'chikazawa' => 4,
                    'oai' => 5,
                    'manager' => 90,
                    'admin' => 91,
                ],
                'noda' => [
                    'miyake' => 1,
                    'morinaga' => 2,
                    'kawamoto' => 3,
                    'shimizu' => 4,
                    'chikazawa' => 5,
                ],
                'saidaiji' => ['chikazawa' => 1],
                'okayama-tomida' => ['chikazawa' => 1],
            ];

            foreach ($memberships as $storeCode => $members) {
                foreach ($members as $key => $displayOrder) {
                    $models[$key]->stores()->syncWithoutDetaching([
                        $stores->get($storeCode)->getKey() => [
                            'display_order' => $displayOrder,
                            'is_active' => true,
                            'started_on' => null,
                            'ended_on' => null,
                        ],
                    ]);
                }
            }

            $models['manager']->managedStores()->syncWithoutDetaching([
                $stores->get('daianji')->getKey() => [
                    'is_active' => true,
                    'started_on' => null,
                    'ended_on' => null,
                ],
            ]);
        });
    }
}
