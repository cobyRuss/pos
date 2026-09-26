<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);
    }

    public function test_settings_are_saved_and_read_back(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Corner Coffee',
                'business_address' => "12 Brew Lane\nKuala Lumpur",
                'business_phone' => '555-0199',
                'currency_symbol' => 'RM',
                'tax_rate' => 6,
                'receipt_footer' => 'Returns within 7 days with this receipt.',
            ])
            ->assertSessionHas('success');

        $this->assertSame('Corner Coffee', Setting::get(Setting::BUSINESS_NAME));
        $this->assertSame('RM', Setting::get(Setting::CURRENCY_SYMBOL));
        $this->assertSame(6.0, Setting::taxRate());
        $this->assertSame('RM', Setting::currency());
        $this->assertSame(
            'Returns within 7 days with this receipt.',
            Setting::get(Setting::RECEIPT_FOOTER),
        );
    }

    public function test_saving_settings_is_audited(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'business_name' => 'Corner Coffee',
            'currency_symbol' => '$',
            'tax_rate' => 0,
        ]);

        $log = AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringContainsString(Setting::BUSINESS_NAME, $log->description);
    }

    public function test_an_absurd_tax_rate_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Corner Coffee',
                'currency_symbol' => '$',
                'tax_rate' => 250,
            ])
            ->assertSessionHasErrors('tax_rate');

        $this->assertNull(Setting::get(Setting::TAX_RATE));
    }

    public function test_a_negative_tax_rate_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Corner Coffee',
                'currency_symbol' => '$',
                'tax_rate' => -5,
            ])
            ->assertSessionHasErrors('tax_rate');
    }

    public function test_the_store_name_is_required(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'business_name' => '',
                'currency_symbol' => '$',
                'tax_rate' => 0,
            ])
            ->assertSessionHasErrors('business_name');
    }

    public function test_staff_cannot_change_settings(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($staff)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Hijacked',
                'currency_symbol' => '$',
                'tax_rate' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(Setting::get(Setting::BUSINESS_NAME));
    }

    public function test_guests_cannot_change_settings(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'))->assertRedirect(route('login'));
    }

    public function test_the_settings_screen_renders(): void
    {
        Setting::put(Setting::BUSINESS_NAME, 'Corner Coffee');

        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Corner Coffee');
    }

    public function test_the_tax_rate_setting_drives_new_sales(): void
    {
        Setting::put(Setting::TAX_RATE, '10');
        Setting::put(Setting::CURRENCY_SYMBOL, 'RM');

        $this->assertSame(10.0, Setting::taxRate());
        $this->assertSame('RM', Setting::currency());
    }

    public function test_an_unset_currency_falls_back_to_the_default(): void
    {
        $this->assertSame(Setting::FALLBACK_CURRENCY, Setting::currency());
    }

    public function test_the_settings_screen_renders_with_no_settings_present(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('business_name', false);
    }
}
