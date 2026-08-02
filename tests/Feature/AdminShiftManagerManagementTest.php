<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminShiftManagerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $staff;

    private Store $daianji;

    private Store $noda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()
            ->where('email', 'admin@example.com')
            ->firstOrFail();
        $this->manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $this->staff = User::query()
            ->where('email', 'otsuki@example.com')
            ->firstOrFail();
        $this->daianji = Store::query()->where('code', 'daianji')->firstOrFail();
        $this->noda = Store::query()->where('code', 'noda')->firstOrFail();
    }

    public function test_staff_list_and_manager_list_use_independent_role_scopes(): void
    {
        $staffResponse = $this->actingAs($this->admin)
            ->get(route('admin.staff.index'))
            ->assertOk()
            ->assertSee($this->staff->name)
            ->assertDontSee('manager-only@example.com');

        $this->assertFalse(
            $staffResponse->viewData('staffMembers')
                ->contains(fn (User $user): bool => $user->is($this->admin)),
        );

        $this->actingAs($this->admin)
            ->get(route('admin.shift-managers.index'))
            ->assertOk()
            ->assertSee($this->manager->name)
            ->assertSee('manager-only@example.com')
            ->assertSee('シフト管理者管理');
    }

    public function test_only_system_admin_can_manage_shift_manager_roles_and_assignments(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.shift-managers.index'))
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->get(route('admin.shift-managers.index'))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->patch(route('admin.shift-managers.update'), [
                'manager_user_ids' => [],
                'store_ids' => [],
            ])
            ->assertForbidden();
    }

    public function test_system_admin_can_promote_staff_and_assign_multiple_stores(): void
    {
        $shiftManagerRoleId = Role::query()
            ->where('code', 'shift_manager')
            ->value('id');

        $this->actingAs($this->admin)
            ->patch(route('admin.shift-managers.update'), [
                'manager_user_ids' => [$this->manager->id, $this->staff->id],
                'store_ids' => [
                    $this->manager->id => [$this->daianji->id],
                    $this->staff->id => [$this->daianji->id, $this->noda->id],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $this->staff->id,
            'role_id' => $shiftManagerRoleId,
        ]);
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $this->staff->id,
            'store_id' => $this->daianji->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $this->staff->id,
            'store_id' => $this->noda->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.staff.index'))
            ->assertOk()
            ->assertSee($this->staff->name);
        $this->actingAs($this->admin)
            ->get(route('admin.shift-managers.index'))
            ->assertOk()
            ->assertSee($this->staff->name);
    }

    public function test_system_admin_can_create_and_edit_dedicated_manager(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.shift-managers.store'), [
                'create_name' => '専任管理者',
                'create_email' => 'dedicated-manager@example.com',
                'create_password' => 'password123',
                'create_password_confirmation' => 'password123',
                'create_store_ids' => [$this->daianji->id, $this->noda->id],
            ])
            ->assertRedirect(route('admin.shift-managers.index'));

        $dedicated = User::query()
            ->where('email', 'dedicated-manager@example.com')
            ->firstOrFail();
        $this->assertTrue($dedicated->hasRole('shift_manager'));
        $this->assertFalse($dedicated->hasRole('staff'));
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $dedicated->id,
            'store_id' => $this->daianji->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $dedicated->id,
            'store_id' => $this->noda->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.shift-managers.profile.update', ['user' => $dedicated]), [
                'profile_name' => '専任管理者（変更）',
                'profile_email' => 'dedicated-manager-updated@example.com',
                'status' => 'on_leave',
                'profile_password' => 'new-password123',
                'profile_password_confirmation' => 'new-password123',
            ])
            ->assertRedirect(route('admin.shift-managers.index'));

        $dedicated->refresh();
        $this->assertSame('専任管理者（変更）', $dedicated->name);
        $this->assertSame('on_leave', $dedicated->status);
        $this->assertTrue(Hash::check('new-password123', $dedicated->password));
    }

    public function test_manager_edit_uses_staff_management_form_layout(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.shift-managers.edit', ['user' => $this->manager]))
            ->assertOk()
            ->assertSee('class="admin-staff-form"', false)
            ->assertSee('担当店舗')
            ->assertSee('name="store_ids[]"', false)
            ->assertSee('専任');
    }

    public function test_profile_update_changes_only_the_target_manager(): void
    {
        $staffManager = User::query()
            ->where('email', 'morinaga@example.com')
            ->firstOrFail();
        $originalStaffManagerName = $staffManager->name;

        $this->actingAs($this->admin)
            ->patch(route('admin.shift-managers.profile.update', ['user' => $this->manager]), [
                'profile_name' => '管理者サンプル（変更）',
                'profile_email' => $this->manager->email,
                'status' => 'active',
                'store_ids' => ['', $this->daianji->id, $this->noda->id],
            ])
            ->assertRedirect(route('admin.shift-managers.index'));

        $this->assertSame(
            '管理者サンプル（変更）',
            $this->manager->refresh()->name,
        );
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $this->manager->id,
            'store_id' => $this->noda->id,
            'is_active' => true,
        ]);
        $this->assertSame($originalStaffManagerName, $staffManager->refresh()->name);
    }

    public function test_removing_manager_role_deactivates_assignments_but_does_not_delete_history(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.shift-managers.update'), [
                'manager_user_ids' => [],
                'store_ids' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->manager->id,
            'role_id' => Role::query()->where('code', 'shift_manager')->value('id'),
        ]);
        $this->assertDatabaseHas('store_shift_manager', [
            'user_id' => $this->manager->id,
            'store_id' => $this->daianji->id,
            'is_active' => false,
        ]);
    }

    public function test_system_admin_cannot_become_shift_manager_or_assign_foreign_store(): void
    {
        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Organization',
            'code' => 'foreign-manager-org',
        ]);
        $foreignStore = Store::query()->create([
            'organization_id' => $foreignOrganization->id,
            'code' => 'foreign-manager-store',
            'name' => 'Foreign Manager Store',
            'display_order' => 0,
            'staffing_check_mode' => 'disabled',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.shift-managers.update'), [
                'manager_user_ids' => [$this->admin->id],
                'store_ids' => [$this->admin->id => [$foreignStore->id]],
            ])
            ->assertSessionHasErrors('manager_user_ids');

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $this->admin->id,
            'role_id' => Role::query()->where('code', 'shift_manager')->value('id'),
        ]);
    }
}
