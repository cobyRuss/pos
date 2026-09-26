<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;
use Tests\TestCase;

class AuthAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe endpoints so the role/permission middleware can be exercised
        // without depending on screens that later phases introduce.
        Route::middleware(['web', 'auth', RoleMiddleware::using(Role::Admin)])
            ->get('/_probe/admin', fn () => 'admin-ok')->name('probe.admin');

        Route::middleware(['web', 'auth', 'permission:'.Permission::PosAccess->value])
            ->get('/_probe/pos', fn () => 'pos-ok')->name('probe.pos');

        $this->admin = User::factory()->create(['email' => 'admin@pos.test', 'password' => 'password']);
        $this->admin->assignRole(Role::Admin);

        $this->staff = User::factory()->create(['email' => 'staff@pos.test', 'password' => 'password']);
        $this->staff->assignRole(Role::Staff);
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_user_can_authenticate(): void
    {
        $response = $this->post('/login', [
            'email' => 'staff@pos.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->staff);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->post('/login', [
            'email' => 'staff@pos.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'staff@pos.test', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'staff@pos.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->post('/login', [
            'email' => 'staff@pos.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_is_ejected_from_existing_session(): void
    {
        $this->actingAs($this->staff);
        $this->staff->update(['is_active' => false]);

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_staff_is_denied_admin_routes_with_flash(): void
    {
        $this->actingAs($this->staff);

        $this->get('/_probe/admin')->assertRedirect(route('dashboard'));
        $this->assertSame('Unauthorized action.', session('error'));
    }

    public function test_staff_gets_403_json_for_ajax_requests(): void
    {
        $this->actingAs($this->staff);

        $this->getJson('/_probe/admin')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Unauthorized action.']);
    }

    public function test_admin_passes_admin_routes(): void
    {
        $this->actingAs($this->admin)
            ->get('/_probe/admin')
            ->assertOk()
            ->assertSee('admin-ok');
    }

    public function test_staff_with_pos_permission_reaches_pos_routes(): void
    {
        $this->actingAs($this->staff)
            ->get('/_probe/pos')
            ->assertOk()
            ->assertSee('pos-ok');
    }

    public function test_permission_gate_denies_staff_and_allows_admin(): void
    {
        $this->assertTrue($this->staff->can(Permission::PosAccess->value));
        $this->assertFalse($this->staff->can(Permission::SettingsManage->value));
        $this->assertTrue($this->admin->can(Permission::SettingsManage->value));
    }

    public function test_logout_works(): void
    {
        $this->actingAs($this->staff);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_change_requires_current_password(): void
    {
        $this->actingAs($this->staff);

        $this->put('/profile/password', [
            'current_password' => 'not-the-password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Auth::guard('web')->validate([
            'email' => 'staff@pos.test',
            'password' => 'password',
        ]));
    }

    public function test_password_change_succeeds_with_current_password(): void
    {
        $this->actingAs($this->staff);

        $this->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'other sessions'));

        $this->assertFalse(Auth::guard('web')->validate([
            'email' => 'staff@pos.test',
            'password' => 'password',
        ]));

        $this->assertTrue(Auth::guard('web')->validate([
            'email' => 'staff@pos.test',
            'password' => 'new-secret-123',
        ]));
    }

    public function test_profile_update_rejects_duplicate_email(): void
    {
        $this->actingAs($this->staff);

        $this->patch('/profile', [
            'name' => 'Renamed',
            'email' => 'admin@pos.test',
        ])->assertSessionHasErrors('email');
    }

    public function test_profile_update_succeeds(): void
    {
        $this->actingAs($this->staff);

        $this->patch('/profile', [
            'name' => 'Renamed',
            'email' => 'staff@pos.test',
            'phone' => '555-0100',
        ])->assertSessionHas('success');

        $this->assertSame('Renamed', $this->staff->fresh()->name);
    }

    public function test_navigation_hides_admin_links_from_staff(): void
    {
        $response = $this->actingAs($this->staff)->get('/dashboard');

        $response->assertOk()
            ->assertSee('POS Terminal')
            ->assertDontSee('Audit Log')
            ->assertDontSee('Settings');
    }

    public function test_navigation_shows_admin_links_to_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Audit Log')
            ->assertSee('Settings');
    }
}
