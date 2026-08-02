<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $systemAdmin;

    private User $manager;

    private Store $daianji;

    private Store $noda;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->systemAdmin = $this->user('admin@example.com');
        $this->manager = $this->user('manager@example.com');
        $this->daianji = $this->store('daianji');
        $this->noda = $this->store('noda');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_list_is_same_organization_role_scoped_filterable_and_compact(): void
    {
        $unroled = User::factory()->create([
            'organization_id' => $this->systemAdmin->organization_id,
            'name' => 'ロールなし',
            'email' => 'unroled@example.net',
            'status' => 'active',
        ]);
        $foreign = $this->foreignUser('別組織スタッフ', 'foreign@example.net');

        $response = $this->actingAs($this->manager)
            ->get(route('admin.staff.index'))
            ->assertOk()
            ->assertSee('氏名・メールアドレス')
            ->assertSee('name="status"', false)
            ->assertSee('name="store_id"', false)
            ->assertSee('name="role"', false)
            ->assertSee('スタッフ追加')
            ->assertDontSee('manager-only@example.com')
            ->assertDontSee($unroled->name)
            ->assertDontSee($foreign->name)
            ->assertDontSee(
                route('admin.staff.edit', ['user' => $this->systemAdmin]),
                false,
            );

        $this->assertFalse(
            $response->viewData('staffMembers')->contains(
                fn (User $user): bool => $user->is($this->systemAdmin),
            ),
        );

        $this->actingAs($this->manager)
            ->get(route('admin.staff.index', ['q' => ' OTSUKI ']))
            ->assertOk()
            ->assertViewHas(
                'staffMembers',
                fn ($staff): bool => $staff->pluck('email')->all()
                    === ['otsuki@example.com'],
            );
        $this->actingAs($this->manager)
            ->get(route('admin.staff.index', ['status' => 'retired']))
            ->assertOk()
            ->assertSee('inactive@example.com');
        $this->actingAs($this->manager)
            ->get(route('admin.staff.index', ['role' => 'shift_manager']))
            ->assertOk()
            ->assertViewHas(
                'staffMembers',
                fn ($staff): bool => $staff->isEmpty(),
            );
        $this->actingAs($this->manager)
            ->get(route('admin.staff.index', ['store_id' => $this->noda->id]))
            ->assertOk()
            ->assertViewHas(
                'staffMembers',
                fn ($staff): bool => $staff->contains(
                    'email',
                    'miyake@example.com',
                ),
            );
    }

    public function test_shift_manager_can_manage_general_staff_but_not_system_admin_or_foreign_user(): void
    {
        $staff = $this->user('miyake@example.com');
        $foreign = $this->foreignUser('別組織スタッフ', 'foreign-edit@example.net');

        $this->actingAs($this->manager)
            ->get(route('admin.staff.edit', ['user' => $staff]))
            ->assertOk();
        $this->actingAs($this->manager)
            ->get(route('admin.staff.edit', ['user' => $this->systemAdmin]))
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->get(route('admin.staff.edit', ['user' => $foreign]))
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->patch(
                route('admin.staff.update', ['user' => $this->systemAdmin]),
                $this->updatePayload($this->systemAdmin, $this->manager),
            )
            ->assertForbidden();
    }

    public function test_shift_manager_registers_staff_for_any_organization_store_without_changing_manager_assignments(): void
    {
        $managementAssignments = $this->tableSnapshot('store_shift_manager');

        $this->actingAs($this->manager)
            ->post(route('admin.staff.store'), $this->createPayload([
                'name' => '新規スタッフ',
                'email' => ' New.Staff@Example.COM ',
                'password' => 'abcdefgh',
                'password_confirmation' => 'abcdefgh',
                'store_ids' => [$this->noda->id, $this->store('saidaiji')->id],
            ]))
            ->assertRedirect();

        $created = $this->user('new.staff@example.com');
        $this->assertTrue(Hash::check('abcdefgh', (string) $created->password));
        $this->assertSame(['staff'], $this->roleCodes($created));
        $this->assertSame(
            [$this->noda->id, $this->store('saidaiji')->id],
            $this->activeStoreIds($created),
        );
        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->noda->id,
            'user_id' => $created->id,
            'is_active' => true,
            'started_on' => '2026-07-30',
            'ended_on' => null,
        ]);
        $this->assertSame(
            $managementAssignments,
            $this->tableSnapshot('store_shift_manager'),
        );
    }

    public function test_system_admin_can_register_and_toggle_shift_manager_role(): void
    {
        $this->actingAs($this->systemAdmin)
            ->post(route('admin.staff.store'), $this->createPayload([
                'name' => '管理候補',
                'email' => 'manager-candidate@example.com',
                'shift_manager_role' => '1',
            ]))
            ->assertRedirect();

        $created = $this->user('manager-candidate@example.com');
        $this->assertSame(
            ['shift_manager', 'staff'],
            $this->roleCodes($created),
        );
        $created->managedStores()->attach($this->daianji->id, [
            'is_active' => true,
            'started_on' => '2026-07-30',
            'ended_on' => null,
        ]);

        $payload = $this->updatePayload($created, $this->systemAdmin);
        $payload['shift_manager_role'] = '0';
        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.staff.update', ['user' => $created]),
                $payload,
            )
            ->assertRedirect();

        $this->assertSame(['staff'], $this->roleCodes($created->refresh()));
        $this->assertDatabaseHas('store_shift_manager', [
            'store_id' => $this->daianji->id,
            'user_id' => $created->id,
            'is_active' => true,
        ]);
    }

    public function test_on_leave_and_retired_users_cannot_log_in(): void
    {
        foreach (['on_leave', 'retired'] as $status) {
            $user = User::factory()->create([
                'organization_id' => $this->systemAdmin->organization_id,
                'name' => $status,
                'email' => "{$status}-login@example.net",
                'password' => Hash::make('abcdefgh'),
                'status' => $status,
            ]);
            $user->roles()->attach($this->roleId('staff'));

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'abcdefgh',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_password_validation_hashing_and_optional_update(): void
    {
        $short = $this->createPayload([
            'email' => 'short-password@example.com',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ]);
        $this->actingAs($this->manager)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $short)
            ->assertRedirect(route('admin.staff.create'))
            ->assertSessionHasErrors('password');

        $mismatch = $this->createPayload([
            'email' => 'mismatch-password@example.com',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgi',
        ]);
        $this->actingAs($this->manager)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $mismatch)
            ->assertSessionHasErrors('password');

        $staff = $this->user('miyake@example.com');
        $oldHash = (string) $staff->password;
        $payload = $this->updatePayload($staff, $this->manager);
        $payload['password'] = '';
        $payload['password_confirmation'] = '';
        $this->actingAs($this->manager)
            ->patch(route('admin.staff.update', ['user' => $staff]), $payload)
            ->assertRedirect();
        $this->assertSame($oldHash, (string) $staff->refresh()->password);

        $payload = $this->updatePayload($staff, $this->manager);
        $payload['password'] = 'plainword';
        $payload['password_confirmation'] = 'plainword';
        $this->actingAs($this->manager)
            ->patch(route('admin.staff.update', ['user' => $staff]), $payload)
            ->assertRedirect();
        $this->assertTrue(
            Hash::check('plainword', (string) $staff->refresh()->password),
        );
        $this->assertNotSame('plainword', (string) $staff->password);
    }

    public function test_validation_failure_keeps_non_password_input_without_flashing_passwords(): void
    {
        $password = 'not-retained-password';
        $confirmation = 'different-not-retained-password';
        $payload = $this->createPayload([
            'name' => ' 入力保持スタッフ ',
            'email' => ' KEPT-INPUT@EXAMPLE.NET ',
            'status' => 'on_leave',
            'password' => $password,
            'password_confirmation' => $confirmation,
            'shift_manager_role' => '1',
            'store_ids' => [$this->daianji->id, $this->noda->id],
        ]);

        $response = $this->actingAs($this->systemAdmin)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $payload)
            ->assertRedirect(route('admin.staff.create'))
            ->assertSessionHasErrors('password')
            ->assertSessionHasInput([
                'name' => '入力保持スタッフ',
                'email' => 'KEPT-INPUT@EXAMPLE.NET',
                'status' => 'on_leave',
                'staff_role' => '1',
                'shift_manager_role' => '1',
                'store_ids' => [
                    (string) $this->daianji->id,
                    (string) $this->noda->id,
                ],
            ])
            ->assertSessionMissingInput([
                'password',
                'password_confirmation',
            ]);

        $oldInput = $response->getSession()->getOldInput();
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('password_confirmation', $oldInput);
        $this->assertNotContains($password, $oldInput, true);
        $this->assertNotContains($confirmation, $oldInput, true);

        $this->get(route('admin.staff.create'))
            ->assertOk()
            ->assertSee('value="入力保持スタッフ"', false)
            ->assertSee('value="KEPT-INPUT@EXAMPLE.NET"', false)
            ->assertDontSee($password, false)
            ->assertDontSee($confirmation, false);

        $withoutPasswords = $payload;
        unset(
            $withoutPasswords['password'],
            $withoutPasswords['password_confirmation'],
        );
        $this->actingAs($this->systemAdmin)
            ->post(route('admin.staff.store'), $withoutPasswords)
            ->assertSessionHasErrors('password');

        $withoutConfirmation = $payload;
        $withoutConfirmation['password_confirmation'] = '';
        $this->actingAs($this->systemAdmin)
            ->post(route('admin.staff.store'), $withoutConfirmation)
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'kept-input@example.net',
        ]);
    }

    public function test_shift_manager_can_only_add_staff_role_and_cannot_mutate_elevated_roles(): void
    {
        $managerOnly = $this->user('manager-only@example.com');
        $payload = $this->updatePayload($managerOnly, $this->manager);
        $payload['staff_role'] = '1';

        $this->actingAs($this->manager)
            ->patch(
                route('admin.staff.update', ['user' => $managerOnly]),
                $payload,
            )
            ->assertRedirect();
        $this->assertSame(
            ['shift_manager', 'staff'],
            $this->roleCodes($managerOnly->refresh()),
        );

        $tampered = $this->updatePayload($managerOnly, $this->manager);
        $tampered['shift_manager_role'] = '0';
        $this->actingAs($this->manager)
            ->from(route('admin.staff.edit', ['user' => $managerOnly]))
            ->patch(
                route('admin.staff.update', ['user' => $managerOnly]),
                $tampered,
            )
            ->assertSessionHasErrors('shift_manager_role');
        $this->assertTrue($managerOnly->refresh()->hasRole('shift_manager'));

        $tampered = $this->updatePayload($managerOnly, $this->manager);
        $tampered['system_admin_role'] = '1';
        $this->actingAs($this->manager)
            ->patch(
                route('admin.staff.update', ['user' => $managerOnly]),
                $tampered,
            )
            ->assertSessionHasErrors('system_admin_role');
    }

    public function test_normalized_foreign_and_soft_deleted_duplicate_emails_are_rejected_safely(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $this->createPayload([
                'email' => ' OTSUKI@EXAMPLE.COM ',
            ]))
            ->assertSessionHasErrors([
                'email' => '同一組織に既に登録されています。既存スタッフを編集してください。',
            ]);

        $foreign = $this->foreignUser(
            '表示してはいけない別組織氏名',
            'foreign-duplicate@example.net',
        );
        $response = $this->actingAs($this->manager)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $this->createPayload([
                'email' => ' FOREIGN-DUPLICATE@EXAMPLE.NET ',
            ]))
            ->assertSessionHasErrors([
                'email' => 'このメールアドレスは使用できません。',
            ]);
        $response->assertSessionDoesntHaveErrors(['name']);
        $this->assertDatabaseHas('users', ['id' => $foreign->id]);

        $deleted = User::factory()->create([
            'organization_id' => $this->systemAdmin->organization_id,
            'name' => '削除済みユーザー',
            'email' => 'deleted-duplicate@example.net',
            'status' => 'retired',
        ]);
        $deleted->roles()->attach($this->roleId('staff'));
        $deleted->delete();
        $this->actingAs($this->manager)
            ->post(route('admin.staff.store'), $this->createPayload([
                'email' => 'DELETED-DUPLICATE@EXAMPLE.NET',
            ]))
            ->assertSessionHasErrors([
                'email' => '過去に登録されたメールアドレスです。通常の新規登録では使用できません。',
            ]);
        $this->assertSame(
            1,
            User::withTrashed()
                ->whereRaw(
                    'LOWER(email) = ?',
                    ['deleted-duplicate@example.net'],
                )
                ->count(),
        );
    }

    public function test_late_membership_failure_rolls_back_user_role_and_store_changes(): void
    {
        $foreign = $this->foreignStore();
        $usersBefore = User::query()->count();
        $rolesBefore = DB::table('role_user')->count();
        $membershipsBefore = DB::table('store_user')->count();

        $this->actingAs($this->manager)
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $this->createPayload([
                'name' => 'ロールバック対象',
                'email' => 'rollback-staff@example.net',
                'store_ids' => [$this->daianji->id, $foreign->id],
            ]))
            ->assertSessionHasErrors('store_ids');

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($rolesBefore, DB::table('role_user')->count());
        $this->assertSame(
            $membershipsBefore,
            DB::table('store_user')->count(),
        );
        $this->assertDatabaseMissing('users', [
            'email' => 'rollback-staff@example.net',
        ]);
    }

    public function test_memberships_support_multiple_stores_reactivation_preservation_and_nonactive_removal(): void
    {
        $target = $this->user('manager-only@example.com');
        $target->roles()->syncWithoutDetaching([$this->roleId('staff')]);
        $membershipId = DB::table('store_user')->insertGetId([
            'store_id' => $this->noda->id,
            'user_id' => $target->id,
            'display_order' => 50,
            'is_active' => false,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-02-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->updatePayload($target, $this->systemAdmin);
        $payload['store_ids'] = [$this->daianji->id, $this->noda->id];
        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.staff.update', ['user' => $target]), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('store_user', [
            'id' => $membershipId,
            'store_id' => $this->noda->id,
            'user_id' => $target->id,
            'is_active' => true,
            'started_on' => '2026-07-30',
            'ended_on' => null,
        ]);
        $this->assertSame(
            [$this->daianji->id, $this->noda->id],
            $this->activeStoreIds($target),
        );

        $payload = $this->updatePayload($target->refresh(), $this->systemAdmin);
        $payload['status'] = 'retired';
        $payload['staff_role'] = '0';
        $payload['shift_manager_role'] = '1';
        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.staff.update', ['user' => $target]), $payload)
            ->assertRedirect();
        $this->assertSame(
            [$this->daianji->id, $this->noda->id],
            $this->activeStoreIds($target),
        );

        $payload = $this->updatePayload($target->refresh(), $this->systemAdmin);
        $payload['staff_role'] = '0';
        $payload['shift_manager_role'] = '1';
        $payload['store_ids'] = [$this->daianji->id];
        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.staff.update', ['user' => $target]), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('store_user', [
            'id' => $membershipId,
            'is_active' => false,
            'ended_on' => '2026-07-30',
        ]);
        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->id,
            'user_id' => $target->id,
            'is_active' => true,
        ]);
    }

    public function test_on_leave_preserves_store_membership_and_existing_shifts_but_blocks_new_candidates(): void
    {
        $this->assertAvailabilityChangePreservesExistingData(
            case: 'on-leave',
            nextStatus: 'on_leave',
            removeStaffRole: false,
        );
    }

    public function test_retired_preserves_store_membership_and_existing_shifts_but_blocks_new_candidates(): void
    {
        $this->assertAvailabilityChangePreservesExistingData(
            case: 'retired',
            nextStatus: 'retired',
            removeStaffRole: false,
        );
    }

    public function test_staff_role_removal_preserves_store_membership_and_existing_shifts_but_blocks_new_candidates(): void
    {
        $this->assertAvailabilityChangePreservesExistingData(
            case: 'role-removed',
            nextStatus: 'active',
            removeStaffRole: true,
        );
    }

    public function test_system_admin_self_role_and_status_are_protected(): void
    {
        $payload = $this->updatePayload(
            $this->systemAdmin,
            $this->systemAdmin,
        );
        $payload['status'] = 'retired';
        $this->actingAs($this->systemAdmin)
            ->from(
                route('admin.staff.edit', ['user' => $this->systemAdmin]),
            )
            ->patch(
                route('admin.staff.update', ['user' => $this->systemAdmin]),
                $payload,
            )
            ->assertSessionHasErrors([
                'status' => 'システム管理者自身を非在籍へ変更できません。',
            ]);
        $this->assertSame('active', $this->systemAdmin->refresh()->status);
        $this->assertTrue($this->systemAdmin->hasRole('system_admin'));

        $payload = $this->updatePayload(
            $this->systemAdmin,
            $this->systemAdmin,
        );
        $payload['system_admin_role'] = '0';
        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.staff.update', ['user' => $this->systemAdmin]),
                $payload,
            )
            ->assertSessionHasErrors('system_admin_role');
        $this->assertTrue(
            $this->systemAdmin->refresh()->hasRole('system_admin'),
        );
    }

    public function test_only_active_system_admin_cannot_change_self_to_on_leave_or_retired(): void
    {
        $this->assertSame(
            1,
            $this->activeSystemAdminCount(
                (int) $this->systemAdmin->organization_id,
            ),
        );

        foreach (['on_leave', 'retired'] as $nextStatus) {
            $payload = $this->updatePayload(
                $this->systemAdmin,
                $this->systemAdmin,
            );
            $payload['status'] = $nextStatus;

            $this->actingAs($this->systemAdmin)
                ->from(route('admin.staff.edit', [
                    'user' => $this->systemAdmin,
                ]))
                ->patch(
                    route('admin.staff.update', [
                        'user' => $this->systemAdmin,
                    ]),
                    $payload,
                )
                ->assertSessionHasErrors('status');

            $this->assertSame(
                'active',
                (string) $this->systemAdmin->refresh()->status,
            );
            $this->assertTrue($this->systemAdmin->hasRole('system_admin'));
        }
    }

    public function test_another_system_admin_cannot_disable_the_only_active_admin_and_foreign_admins_do_not_count(): void
    {
        $inactiveActor = $this->createSystemAdmin(
            organization: $this->systemAdmin->organization,
            email: 'inactive-admin@example.net',
            status: 'on_leave',
        );
        $foreignOrganization = Organization::query()->create([
            'name' => '別管理組織',
            'code' => 'foreign-admin-org',
            'is_active' => true,
        ]);
        $this->createSystemAdmin(
            organization: $foreignOrganization,
            email: 'foreign-admin@example.net',
            status: 'active',
        );
        $this->assertSame(
            1,
            $this->activeSystemAdminCount(
                (int) $this->systemAdmin->organization_id,
            ),
        );

        $inactiveActor->setAttribute('status', 'active');
        $payload = $this->updatePayload(
            $this->systemAdmin,
            $inactiveActor,
        );
        $payload['status'] = 'retired';

        $this->actingAs($inactiveActor)
            ->from(route('admin.staff.edit', [
                'user' => $this->systemAdmin,
            ]))
            ->patch(
                route('admin.staff.update', [
                    'user' => $this->systemAdmin,
                ]),
                $payload,
            )
            ->assertSessionHasErrors([
                'status' => '同一組織の最後の有効なシステム管理者を非在籍へ変更できません。',
            ]);

        $this->assertSame(
            'active',
            (string) $this->systemAdmin->refresh()->status,
        );
        $this->assertSame(
            1,
            $this->activeSystemAdminCount(
                (int) $this->systemAdmin->organization_id,
            ),
        );
    }

    public function test_second_active_same_organization_system_admin_can_change_another_admins_status(): void
    {
        $secondAdmin = $this->createSystemAdmin(
            organization: $this->systemAdmin->organization,
            email: 'second-admin@example.net',
            status: 'active',
        );
        $this->assertSame(
            2,
            $this->activeSystemAdminCount(
                (int) $this->systemAdmin->organization_id,
            ),
        );
        $payload = $this->updatePayload(
            $this->systemAdmin,
            $secondAdmin,
        );
        $payload['status'] = 'on_leave';

        $this->actingAs($secondAdmin)
            ->patch(
                route('admin.staff.update', [
                    'user' => $this->systemAdmin,
                ]),
                $payload,
            )
            ->assertRedirect();

        $this->assertSame(
            'on_leave',
            (string) $this->systemAdmin->refresh()->status,
        );
        $this->assertSame(
            1,
            $this->activeSystemAdminCount(
                (int) $this->systemAdmin->organization_id,
            ),
        );
    }

    public function test_nonactive_or_role_removed_staff_keep_existing_drafts_and_public_rows_editable(): void
    {
        $target = $this->user('otsuki@example.com');
        $viewer = $this->user('fujimoto@example.com');
        $patternC = $this->pattern('C');
        $patternD = $this->pattern('D');
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $this->daianji->id,
            'target_month' => '2026-10-01',
            'draft_version' => 1,
            'published_version' => 1,
            'published_draft_version' => 1,
            'shift_updated_at' => now(),
            'published_at' => now(),
            'published_by_user_id' => $this->manager->id,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
        $shift = Shift::query()->create([
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
            'work_date' => '2026-10-10',
            'store_shift_pattern_id' => $patternC->id,
            'sequence' => 1,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => 'C',
            'work_hours' => $patternC->work_hours,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
        PublishedShift::query()->create([
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
            'work_date' => '2026-10-10',
            'sequence' => 1,
            'pattern_code' => 'C',
            'work_hours' => $patternC->work_hours,
            'published_at' => now(),
        ]);

        $payload = $this->updatePayload($target, $this->systemAdmin);
        $payload['status'] = 'retired';
        $payload['staff_role'] = '0';
        $payload['shift_manager_role'] = '0';
        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.staff.update', ['user' => $target]), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->id,
            'user_id' => $target->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
        ]);

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-10')
            ->assertOk()
            ->assertSee($target->name)
            ->assertSee('data-can-create-shift="false"', false)
            ->assertSee('data-shift-id="'.$shift->id.'"', false);

        $this->actingAs($this->manager)
            ->patchJson(
                route('admin.shifts.update', [
                    'store' => $this->daianji->code,
                    'shift' => $shift->id,
                ]),
                [
                    'target_month' => '2026-10',
                    'expected_draft_version' => 1,
                    'shift_pattern_id' => $patternD->id,
                ],
            )
            ->assertOk()
            ->assertJson(['pattern_code' => 'D', 'draft_version' => 2]);

        $this->actingAs($this->manager)
            ->postJson(
                route('admin.shifts.store', ['store' => $this->daianji->code]),
                [
                    'target_month' => '2026-10',
                    'expected_draft_version' => 2,
                    'user_id' => $target->id,
                    'work_date' => '2026-10-11',
                    'shift_pattern_id' => $patternD->id,
                    'entry_uuid' => (string) Str::uuid(),
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($viewer)
            ->get('/staff/store/daianji?month=2026-10')
            ->assertOk()
            ->assertViewHas('store', function (array $store) use ($target): bool {
                $row = collect($store['staff'])->firstWhere('id', $target->id);

                return ($row['shifts']['2026-10-10']['shift_type']['code'] ?? null)
                    === 'C';
            });

        $this->actingAs($this->manager)
            ->deleteJson(
                route('admin.shifts.destroy', [
                    'store' => $this->daianji->code,
                    'shift' => $shift->id,
                ]),
                [
                    'target_month' => '2026-10',
                    'expected_draft_version' => 2,
                ],
            )
            ->assertOk();
        $this->assertDatabaseMissing('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_staff_form_is_single_save_and_has_dirty_state_controls(): void
    {
        $staff = $this->user('miyake@example.com');

        $this->actingAs($this->systemAdmin)
            ->get(route('admin.staff.edit', ['user' => $staff]))
            ->assertOk()
            ->assertSee('data-admin-staff-form', false)
            ->assertSee('data-admin-staff-save', false)
            ->assertSee('name="store_ids[]"', false)
            ->assertSee('name="shift_manager_role"', false)
            ->assertSee('admin-staff-management.js');

        $script = file_get_contents(
            public_path('js/admin-staff-management.js'),
        );
        $this->assertIsString($script);
        $this->assertStringContainsString('beforeunload', $script);
        $this->assertStringContainsString('isSubmitting', $script);
        $this->assertStringContainsString('saveButton.disabled', $script);
    }

    private function assertAvailabilityChangePreservesExistingData(
        string $case,
        string $nextStatus,
        bool $removeStaffRole,
    ): void {
        $target = User::factory()->create([
            'organization_id' => $this->systemAdmin->organization_id,
            'name' => '既存勤務 '.$case,
            'email' => $case.'@example.net',
            'status' => 'active',
        ]);
        $target->roles()->attach($this->roleId('staff'));
        DB::table('store_user')->insert([
            'store_id' => $this->daianji->id,
            'user_id' => $target->id,
            'display_order' => 90,
            'is_active' => true,
            'started_on' => '2026-01-01',
            'ended_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $membershipBefore = (array) DB::table('store_user')
            ->where('store_id', $this->daianji->id)
            ->where('user_id', $target->id)
            ->first();
        $pattern = $this->pattern('C');
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $this->daianji->id,
            'target_month' => '2026-10-01',
            'draft_version' => 1,
            'published_version' => 1,
            'published_draft_version' => 1,
            'shift_updated_at' => now(),
            'published_at' => now(),
            'published_by_user_id' => $this->manager->id,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
        $shift = Shift::query()->create([
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
            'work_date' => '2026-10-10',
            'store_shift_pattern_id' => $pattern->id,
            'sequence' => 1,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => 'C',
            'work_hours' => $pattern->work_hours,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
        PublishedShift::query()->create([
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
            'work_date' => '2026-10-10',
            'sequence' => 1,
            'pattern_code' => 'C',
            'work_hours' => $pattern->work_hours,
            'published_at' => now(),
        ]);

        $payload = $this->updatePayload($target, $this->systemAdmin);
        $payload['status'] = $nextStatus;

        if ($removeStaffRole) {
            $payload['staff_role'] = '0';
        }

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.staff.update', ['user' => $target]),
                $payload,
            )
            ->assertRedirect();

        $membershipAfter = (array) DB::table('store_user')
            ->where('store_id', $this->daianji->id)
            ->where('user_id', $target->id)
            ->first();
        $this->assertSame($membershipBefore, $membershipAfter);
        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->id,
            'user_id' => $target->id,
            'is_active' => true,
            'ended_on' => null,
        ]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
        ]);

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-10')
            ->assertOk()
            ->assertSee($target->name)
            ->assertSee('data-user-id="'.$target->id.'"', false)
            ->assertSee('data-can-create-shift="false"', false)
            ->assertSee('data-shift-id="'.$shift->id.'"', false);

        $this->actingAs($this->manager)
            ->postJson(
                route('admin.shifts.store', [
                    'store' => $this->daianji->code,
                ]),
                [
                    'target_month' => '2026-10',
                    'expected_draft_version' => 1,
                    'user_id' => $target->id,
                    'work_date' => '2026-10-11',
                    'shift_pattern_id' => $pattern->id,
                    'entry_uuid' => (string) Str::uuid(),
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $schedule->id,
            'user_id' => $target->id,
        ]);
    }

    private function createSystemAdmin(
        Organization $organization,
        string $email,
        string $status,
    ): User {
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => $email,
            'email' => $email,
            'status' => $status,
        ]);
        $admin->roles()->attach($this->roleId('system_admin'));

        return $admin;
    }

    private function activeSystemAdminCount(int $organizationId): int
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas(
                'roles',
                fn ($query) => $query->where(
                    'roles.code',
                    'system_admin',
                ),
            )
            ->count();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => '登録スタッフ',
            'email' => 'created-'.Str::lower(Str::random(8)).'@example.net',
            'status' => 'active',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
            'staff_role' => '1',
            'store_ids' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(User $target, User $actor): array
    {
        $target->refresh()->load('roles');
        $payload = [
            'name' => $target->name,
            'email' => $target->email,
            'status' => $target->status,
            'password' => null,
            'password_confirmation' => null,
            'staff_role' => $target->hasRole('staff') ? '1' : '0',
            'store_ids' => $this->activeStoreIds($target),
        ];

        if ($actor->hasRole('system_admin')) {
            $payload['shift_manager_role'] = $target->hasRole(
                'shift_manager',
            ) ? '1' : '0';
        }

        return $payload;
    }

    /**
     * @return list<int>
     */
    private function activeStoreIds(User $user): array
    {
        return DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function roleCodes(User $user): array
    {
        return $user->roles()
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    private function roleId(string $code): int
    {
        return (int) Role::query()->where('code', $code)->firstOrFail()->id;
    }

    private function user(string $email): User
    {
        return User::withTrashed()->where('email', $email)->firstOrFail();
    }

    private function store(string $code): Store
    {
        return Store::query()->where('code', $code)->firstOrFail();
    }

    private function pattern(string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $this->daianji->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    private function foreignUser(string $name, string $email): User
    {
        $organization = Organization::query()->create([
            'name' => '別組織',
            'code' => 'foreign-'.Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'status' => 'active',
        ]);
        $user->roles()->attach($this->roleId('staff'));

        return $user;
    }

    private function foreignStore(): Store
    {
        $organization = Organization::query()->create([
            'name' => '別店舗組織',
            'code' => 'foreign-store-org-'.Str::lower(Str::random(8)),
            'is_active' => true,
        ]);

        return Store::query()->create([
            'organization_id' => $organization->id,
            'code' => 'foreign-store',
            'name' => '別組織店舗',
            'area' => '別地域',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableSnapshot(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
