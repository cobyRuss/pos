<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);
    }

    public function test_an_admin_can_create_a_staff_account(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.store'), [
                'name' => 'Sam Lee',
                'email' => 'sam@example.test',
                'phone' => '555-0100',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'role' => Role::Staff->value,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.staff.index'))
            ->assertSessionHas('success');

        $user = User::where('email', 'sam@example.test')->sole();

        $this->assertTrue($user->hasRole(Role::Staff));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('correct-horse', $user->password));
    }

    public function test_creating_a_staff_account_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.store'), [
            'name' => 'Sam Lee',
            'email' => 'sam@example.test',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'role' => Role::Staff->value,
        ]);

        $log = AuditLog::where('action', AuditLogger::STAFF_CREATED)->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(User::class, $log->model_type);
        $this->assertStringContainsString('sam@example.test', $log->description);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'sam@example.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.staff.store'), [
                'name' => 'Another Sam',
                'email' => 'sam@example.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'role' => Role::Staff->value,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_a_duplicate_email_in_a_different_case_is_rejected(): void
    {
        User::factory()->create(['email' => 'Sam@Example.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.staff.store'), [
                'name' => 'Another Sam',
                'email' => 'sam@example.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'role' => Role::Staff->value,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_a_password_must_be_confirmed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.store'), [
                'name' => 'Sam Lee',
                'email' => 'sam@example.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'different',
                'role' => Role::Staff->value,
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::where('email', 'sam@example.test')->count());
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.store'), [
                'name' => 'Sam Lee',
                'email' => 'sam@example.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'role' => 'superuser',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_an_admin_can_change_a_role(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($this->admin)
            ->put(route('admin.staff.update', $staff), [
                'name' => $staff->name,
                'email' => $staff->email,
                'role' => Role::Admin->value,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.staff.index'))
            ->assertSessionHas('success');

        $this->assertTrue($staff->fresh()->hasRole(Role::Admin));
    }

    public function test_editing_without_a_password_keeps_the_old_one(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);
        $original = $staff->password;

        $this->actingAs($this->admin)->put(route('admin.staff.update', $staff), [
            'name' => 'Renamed',
            'email' => $staff->email,
            'role' => Role::Staff->value,
            'is_active' => 1,
        ])->assertSessionHas('success');

        $staff->refresh();

        $this->assertSame('Renamed', $staff->name);
        $this->assertSame($original, $staff->password);
    }

    public function test_an_admin_cannot_remove_their_own_admin_access(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.staff.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => Role::Staff->value,
                'is_active' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin));
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        // The form posts is_active=0 via its hidden companion field when the
        // checkbox is cleared.
        $this->actingAs($this->admin)
            ->put(route('admin.staff.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => Role::Admin->value,
                'is_active' => 0,
            ])
            ->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.staff.destroy', $this->admin))
            ->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_deactivating_a_staff_member_stops_their_sign_in(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($this->admin)
            ->delete(route('admin.staff.destroy', $staff))
            ->assertSessionHas('success');

        $this->assertFalse($staff->fresh()->is_active);

        // Sign the admin out first: /login is behind the guest middleware and
        // would just bounce an authenticated request.
        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_member_can_be_reactivated(): void
    {
        $staff = User::factory()->create(['is_active' => false]);
        $staff->assignRole(Role::Staff);

        $this->actingAs($this->admin)
            ->delete(route('admin.staff.destroy', $staff))
            ->assertSessionHas('success');

        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_an_account_with_sales_history_cannot_be_deleted(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);
        Order::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.staff.purge', $staff))
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($staff->id));
    }

    public function test_an_account_with_no_history_can_be_deleted(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($this->admin)
            ->delete(route('admin.staff.purge', $staff))
            ->assertSessionHas('success');

        $this->assertNull(User::find($staff->id));
    }

    public function test_staff_cannot_manage_other_accounts(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($staff)
            ->get(route('admin.staff.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($staff)
            ->post(route('admin.staff.store'), [
                'name' => 'Intruder',
                'email' => 'intruder@example.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'role' => Role::Admin->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(User::where('email', 'intruder@example.test')->first());
    }

    public function test_guests_cannot_manage_staff(): void
    {
        $this->get(route('admin.staff.index'))->assertRedirect(route('login'));
        $this->get(route('admin.staff.create'))->assertRedirect(route('login'));
    }

    public function test_the_staff_list_can_be_filtered(): void
    {
        $active = User::factory()->create(['name' => 'Active Sam']);
        $active->assignRole(Role::Staff);

        $inactive = User::factory()->create(['name' => 'Inactive Kim', 'is_active' => false]);
        $inactive->assignRole(Role::Staff);

        $this->actingAs($this->admin)
            ->get(route('admin.staff.index', ['q' => 'Sam']))
            ->assertOk()
            ->assertSee('Active Sam')
            ->assertDontSee('Inactive Kim');

        $this->actingAs($this->admin)
            ->get(route('admin.staff.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Inactive Kim')
            ->assertDontSee('Active Sam');
    }

    public function test_the_staff_screens_render(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($this->admin)->get(route('admin.staff.index'))->assertOk()->assertSee($staff->email);
        $this->actingAs($this->admin)->get(route('admin.staff.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.staff.edit', $staff))->assertOk()->assertSee($staff->email);
    }
}
