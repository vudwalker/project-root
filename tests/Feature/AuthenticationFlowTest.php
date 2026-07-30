<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);
        $this->password = Str::random(48);
        RateLimiter::clear($this->throttleKey('staff@example.com'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_login_screen_is_available_and_does_not_redisplay_passwords(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'staff@example.com',
            'password' => $this->password,
        ]);

        $response
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('メールアドレス')
            ->assertSee('パスワード')
            ->assertSee('name="_token"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee($this->password);
    }

    public function test_active_user_can_login_and_session_is_regenerated_and_persisted(): void
    {
        $user = $this->setPassword($this->user('staff@example.com'));
        Session::start();
        $oldSessionId = Session::getId();

        $this->post('/login', [
            'email' => strtoupper($user->email),
            'password' => $this->password,
        ])
            ->assertRedirect('/staff')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, Session::getId());

        $this->get('/staff?month=2026-07')
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_wrong_password_and_unknown_email_use_the_same_failure_message(): void
    {
        $this->setPassword($this->user('staff@example.com'));
        $message = 'メールアドレスまたはパスワードが正しくありません。';

        $this->from('/login')->post('/login', [
            'email' => 'staff@example.com',
            'password' => Str::random(48),
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();

        $this->from('/login')->post('/login', [
            'email' => 'unknown-'.Str::random(12).'@example.com',
            'password' => Str::random(48),
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();
    }

    public function test_non_active_and_soft_deleted_users_cannot_login(): void
    {
        foreach (['on_leave', 'retired'] as $status) {
            $user = $this->setPassword($this->user('staff@example.com'));
            $user->update(['status' => $status]);

            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => $this->password,
            ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['email']);
            $this->assertGuest();
            RateLimiter::clear($this->throttleKey($user->email));
            $user->update(['status' => 'active']);
        }

        $deletedUser = $this->setPassword($this->user('staff@example.com'));
        $deletedUser->delete();

        $this->from('/login')->post('/login', [
            'email' => $deletedUser->email,
            'password' => $this->password,
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_role_priority_redirects_each_user_to_the_expected_screen(): void
    {
        $cases = [
            'admin@example.com' => '/admin',
            'manager@example.com' => '/admin/shifts/stores/daianji',
            'manager-only@example.com' => '/admin',
            'staff@example.com' => '/staff',
        ];

        foreach ($cases as $email => $destination) {
            auth()->logout();
            Session::flush();
            $user = $this->setPassword($this->user($email));

            $this->post('/login', [
                'email' => $email,
                'password' => $this->password,
            ])->assertRedirect($destination);

            $this->assertAuthenticatedAs($user);
        }
    }

    public function test_shift_manager_without_an_assigned_store_gets_a_safe_notice(): void
    {
        $manager = $this->setPassword($this->user('manager-only@example.com'));

        $this->post('/login', [
            'email' => $manager->email,
            'password' => $this->password,
        ])->assertRedirect('/admin');

        $this->get('/admin')
            ->assertOk()
            ->assertSee('管理対象店舗がありません');
    }

    public function test_system_admin_without_stores_gets_a_safe_notice(): void
    {
        $admin = $this->setPassword($this->user('admin@example.com'));
        $organization = Organization::query()->create([
            'code' => 'empty-organization',
            'name' => '店舗なし組織',
            'is_active' => true,
        ]);
        $admin->update(['organization_id' => $organization->getKey()]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => $this->password,
        ])->assertRedirect('/admin');

        $this->get('/admin')
            ->assertOk()
            ->assertSee('管理対象店舗がありません');
    }

    public function test_allowed_intended_url_is_restored_after_login(): void
    {
        $target = '/admin/shifts/stores/daianji?month=2026-07';

        $this->get($target)->assertRedirect('/login');

        $manager = $this->setPassword($this->user('manager@example.com'));
        $this->post('/login', [
            'email' => $manager->email,
            'password' => $this->password,
        ])->assertRedirect($target);

        $this->get($target)->assertOk();
    }

    public function test_forbidden_intended_url_does_not_create_a_redirect_loop(): void
    {
        $target = '/admin/shifts/stores/daianji?month=2026-07';

        $this->get($target)->assertRedirect('/login');

        $staff = $this->setPassword($this->user('staff@example.com'));
        $this->post('/login', [
            'email' => $staff->email,
            'password' => $this->password,
        ])->assertRedirect($target);

        $this->get($target)->assertForbidden();
    }

    public function test_guests_are_redirected_to_login_for_staff_and_admin_routes(): void
    {
        $this->get('/staff?month=2026-07')
            ->assertRedirect('/login');
        $this->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertRedirect('/login');
    }

    public function test_role_and_store_authorization_are_enforced_server_side(): void
    {
        $staff = $this->user('staff@example.com');
        $this->actingAs($staff)
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertForbidden();

        $managerOnly = $this->user('manager-only@example.com');
        $this->actingAs($managerOnly)
            ->get('/staff?month=2026-07')
            ->assertForbidden();
        $this->get('/admin')
            ->assertOk();

        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)
            ->get('/admin/shifts/stores/noda?month=2026-07')
            ->assertOk();

        $managerAndStaff = $this->user('manager@example.com');
        $managerAndStaff->roles()->syncWithoutDetaching([
            Role::query()->where('code', 'staff')->firstOrFail()->getKey(),
        ]);
        $managerAndStaff->stores()->syncWithoutDetaching([
            Store::query()->where('code', 'daianji')->firstOrFail()->getKey() => [
                'display_order' => 99,
                'is_active' => true,
                'started_on' => null,
                'ended_on' => null,
            ],
        ]);
        $this->actingAs($managerAndStaff)
            ->get('/staff?month=2026-07')
            ->assertOk();
        $this->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk();
        $this->get('/admin/shifts/stores/noda?month=2026-07')
            ->assertForbidden();
    }

    public function test_user_cannot_open_a_store_from_another_organization(): void
    {
        $organization = Organization::query()->create([
            'code' => 'foreign-company',
            'name' => '別組織',
            'is_active' => true,
        ]);
        Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'foreign-store',
            'name' => '別組織店舗',
            'status' => 'active',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
        ]);

        $this->actingAs($this->user('admin@example.com'))
            ->get('/admin/shifts/stores/foreign-store?month=2026-07')
            ->assertNotFound();
    }

    public function test_login_attempts_are_rate_limited_and_success_clears_failures(): void
    {
        $user = $this->setPassword($this->user('staff@example.com'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => Str::random(48),
            ])->assertSessionHasErrors(['email']);
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => $this->password,
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email']);
        $this->assertGuest();

        RateLimiter::clear($this->throttleKey($user->email));

        $this->post('/login', [
            'email' => $user->email,
            'password' => $this->password,
        ])->assertRedirect('/staff');

        $this->assertSame(0, RateLimiter::attempts($this->throttleKey($user->email)));
    }

    public function test_logout_requires_post_and_invalidates_the_session(): void
    {
        $user = $this->user('staff@example.com');
        $this->actingAs($user)->withSession(['login-marker' => 'present']);
        Session::start();
        $oldSessionId = Session::getId();

        $this->get('/logout')->assertMethodNotAllowed();

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionMissing('login-marker');

        $this->assertGuest();
        $this->assertNotSame($oldSessionId, Session::getId());
        $this->get('/staff?month=2026-07')->assertRedirect('/login');
    }

    public function test_development_seeder_keeps_management_accounts_out_of_staff_memberships(): void
    {
        $this->assertSame(['staff'], $this->roleCodes('staff@example.com'));
        $this->assertSame(['shift_manager'], $this->roleCodes('manager-only@example.com'));
        $this->assertSame(['shift_manager'], $this->roleCodes('manager@example.com'));
        $this->assertSame(['system_admin'], $this->roleCodes('admin@example.com'));
        $this->assertNull($this->user('manager@example.com')->primary_store_id);
        $this->assertNull($this->user('admin@example.com')->primary_store_id);
        $this->assertFalse($this->user('manager@example.com')->stores()->exists());
        $this->assertFalse($this->user('admin@example.com')->stores()->exists());
        $this->assertSame('retired', $this->user('inactive@example.com')->status);

        config()->set('development.login_password', $this->password);
        $this->seed(RoleUserSeeder::class);

        foreach ([
            'staff@example.com',
            'manager-only@example.com',
            'manager@example.com',
            'admin@example.com',
            'inactive@example.com',
        ] as $email) {
            $this->assertTrue(Hash::check(
                $this->password,
                (string) $this->user($email)->password,
            ));
        }
    }

    public function test_authentication_navigation_does_not_modify_shift_data(): void
    {
        $before = [
            ShiftSchedule::query()->count(),
            Shift::query()->count(),
            PublishedShift::query()->count(),
        ];

        $manager = $this->setPassword($this->user('manager@example.com'));
        $this->post('/login', [
            'email' => $manager->email,
            'password' => $this->password,
        ])->assertRedirect('/admin/shifts/stores/daianji');
        $this->get('/admin/shifts/stores/daianji?month=2026-07')->assertOk();
        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame($before, [
            ShiftSchedule::query()->count(),
            Shift::query()->count(),
            PublishedShift::query()->count(),
        ]);
    }

    private function setPassword(User $user): User
    {
        $user->forceFill([
            'password' => Hash::make($this->password),
        ])->save();

        return $user->refresh();
    }

    private function user(string $email): User
    {
        return User::withTrashed()
            ->where('email', $email)
            ->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function roleCodes(string $email): array
    {
        return $this->user($email)
            ->roles()
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    private function throttleKey(string $email): string
    {
        return Str::transliterate(Str::lower($email).'|127.0.0.1');
    }
}
