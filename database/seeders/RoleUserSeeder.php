<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
                    'status' => 'active',
                    'roles' => ['system_admin'],
                ],
                'manager' => [
                    'name' => '管理者サンプル',
                    'email' => 'manager@example.com',
                    'status' => 'active',
                    'roles' => ['shift_manager'],
                ],
                'chikazawa' => [
                    'name' => '近澤幸次',
                    'email' => 'staff@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'otsuki' => [
                    'name' => '大月敦弘',
                    'email' => 'otsuki@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'fujimoto' => [
                    'name' => '藤本保子',
                    'email' => 'fujimoto@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'motoyama' => [
                    'name' => '本山宏明',
                    'email' => 'motoyama@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'oai' => [
                    'name' => '小合達也',
                    'email' => 'oai@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'miyake' => [
                    'name' => '三宅由幸',
                    'email' => 'miyake@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'morinaga' => [
                    'name' => '森永俊巳',
                    'email' => 'morinaga@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'kawamoto' => [
                    'name' => '河本健二',
                    'email' => 'kawamoto@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
                'shimizu' => [
                    'name' => '清水輝夫',
                    'email' => 'shimizu@example.com',
                    'status' => 'active',
                    'roles' => ['staff'],
                ],
            ];

            if (app()->environment(['local', 'testing'])) {
                $users['manager_only'] = [
                    'name' => 'シフト管理者専用',
                    'email' => 'manager-only@example.com',
                    'status' => 'active',
                    'roles' => ['shift_manager'],
                ];
                $users['inactive'] = [
                    'name' => '利用停止スタッフ',
                    'email' => 'inactive@example.com',
                    'status' => 'retired',
                    'roles' => ['staff'],
                ];
            }

            $developmentPassword = $this->developmentPassword();
            $models = [];

            foreach ($users as $key => $attributes) {
                $models[$key] = $this->updateOrCreateUser(
                    email: $attributes['email'],
                    attributes: [
                        'organization_id' => $organization->getKey(),
                        'name' => $attributes['name'],
                        'status' => $attributes['status'],
                    ],
                    developmentPassword: $developmentPassword,
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

            // 過去のSeederで付与された管理専用アカウントの勤務所属を除去します。
            foreach (['manager', 'admin'] as $managementOnlyUser) {
                $models[$managementOnlyUser]->stores()->detach();
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

    /**
     * 開発環境で値が設定された場合だけ、既存ユーザーのパスワードを更新します。
     */
    private function developmentPassword(): ?string
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        $password = config('development.login_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * @param  array{organization_id: int, name: string, status: string}  $attributes
     */
    private function updateOrCreateUser(
        string $email,
        array $attributes,
        ?string $developmentPassword,
    ): User {
        $user = User::withTrashed()
            ->where('email', $email)
            ->first() ?? new User(['email' => $email]);

        $user->fill($attributes);

        if (! $user->exists || $developmentPassword !== null) {
            $plainPassword = $developmentPassword ?? Str::random(64);
            $user->password = Hash::make($plainPassword);
        }

        $user->save();

        return $user;
    }
}
