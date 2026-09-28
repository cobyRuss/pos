<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nina New',
            'email' => 'nina@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => UserRole::Staff->value,
            'is_active' => 1,
        ], $overrides);
    }

    public function test_an_admin_can_create_a_staff_member(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->payload())
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'nina@example.test',
            'role' => UserRole::Staff->value,
            'is_active' => true,
        ]);

        $created = User::where('email', 'nina@example.test')->sole();
        $this->assertTrue($created->isStaff());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::USER_CREATED]);
    }

    public function test_a_password_is_hashed_and_never_echoed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), $this->payload());

        $created = User::where('email', 'nina@example.test')->sole();

        $this->assertNotSame('secret-password', $created->password);
        $this->assertTrue(password_verify('secret-password', $created->password));
    }

    public function test_the_email_must_be_unique(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'nina@example.test']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->payload())
            ->assertSessionHasErrors('email');
    }

    public function test_the_password_confirmation_must_match(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->payload(['password_confirmation' => 'different']))
            ->assertSessionHasErrors('password');
    }

    public function test_a_user_can_be_updated_without_changing_their_password(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create(['name' => 'Old Name']);
        $originalHash = $staff->password;

        $this->actingAs($admin)
            ->put(route('admin.users.update', $staff), [
                'name' => 'New Name',
                'email' => $staff->email,
                'role' => UserRole::Staff->value,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.users.index'));

        $staff->refresh();

        $this->assertSame('New Name', $staff->name);
        $this->assertSame($originalHash, $staff->password);
    }

    public function test_a_new_password_replaces_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => UserRole::Staff->value,
            'is_active' => 1,
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
        ]);

        $this->assertTrue(password_verify('brand-new-secret', $staff->fresh()->password));
    }

    public function test_a_user_can_be_deactivated_and_reactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => UserRole::Staff->value,
            'is_active' => 0,
        ]);

        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => false]);
    }

    public function test_the_last_active_admin_cannot_be_demoted_or_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        // Demoting one of two admins is fine.
        $this->actingAs($admin)->put(route('admin.users.update', $other), [
            'name' => $other->name,
            'email' => $other->email,
            'role' => UserRole::Staff->value,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame(UserRole::Staff, $other->fresh()->role);

        // Now the original admin is the only one left and must keep the role.
        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Staff->value,
            'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => UserRole::Admin->value,
                'is_active' => 0,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_an_admin_can_delete_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $staff))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_an_inactive_user_cannot_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->post(route('login.store'), [
            'email' => 'gone@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->assertSame($admin->id, User::find($admin->id)->id);
    }

    public function test_staff_cannot_manage_users(): void
    {
        $staff = User::factory()->staff()->create();
        $target = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('admin.users.index'))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->get(route('admin.users.create'))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->post(route('admin.users.store'), $this->payload())->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->delete(route('admin.users.destroy', $target))->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_user_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create(['name' => 'Rendered Staff']);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Rendered Staff');
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.edit', $staff))->assertOk();
    }

    public function test_the_role_middleware_bounces_the_wrong_role_and_logs_it(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('pos.index'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACCESS_DENIED,
            'user_id' => $staff->id,
        ]);

        // Admins are pushed to the dashboard, staff to the till.
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('pos.index'));
    }

    public function test_the_role_middleware_rejects_an_unknown_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        foreach ([
            'admin.dashboard',
            'pos.index',
            'products.index',
            'inventory.index',
            'orders.index',
            'admin.users.index',
            'admin.settings.edit',
            'admin.reports.sales',
        ] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_each_role_lands_on_its_own_home_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('home'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('home'))
            ->assertRedirect(route('pos.index'));
    }
}
