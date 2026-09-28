<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_reachable_by_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in', false);
    }

    public function test_the_login_form_has_no_keep_me_signed_in_option(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Keep me signed in')
            ->assertDontSee('name="remember"', false);
    }

    public function test_signing_in_never_issues_a_remember_cookie(): void
    {
        $admin = User::factory()->admin()->create();

        // The factory seeds a token, so start from a genuinely clean account.
        $admin->forceFill(['remember_token' => null])->save();

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
            // A hand-crafted request must not be able to turn remembering back on.
            'remember' => 1,
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertNull($admin->fresh()->remember_token);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/pos')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_admin_is_sent_to_the_dashboard_after_signing_in(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_staff_is_sent_to_the_pos_after_signing_in(): void
    {
        $staff = User::factory()->staff()->create();

        $response = $this->post('/login', [
            'email' => $staff->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('pos.index'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_last_login_timestamp_and_audit_entry_are_recorded(): void
    {
        $admin = User::factory()->admin()->create(['last_login_at' => null]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::LOGIN]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->post('/login', ['email' => $staff->email, 'password' => 'not-the-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_accounts_cannot_sign_in(): void
    {
        $staff = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::FAILED_LOGIN]);
    }

    public function test_users_can_sign_out(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::LOGOUT]);
    }

    public function test_root_sends_each_role_to_its_own_home_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs(User::factory()->staff()->create())
            ->get('/')
            ->assertRedirect(route('pos.index'));
    }

    public function test_audit_log_actor_falls_back_to_system(): void
    {
        $log = AuditLog::create([
            'user_name' => 'Nightly job',
            'action' => AuditLogger::ORDER_CREATED,
            'description' => 'Imported orders.',
        ]);

        $this->assertSame('Nightly job', $log->actor);
    }
}
