<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A seeded install has to be usable: someone must be able to sign in as an
 * administrator, otherwise staff management, settings and reports are all
 * unreachable on a fresh build.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_creates_an_administrator_and_a_staff_member(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->sole();
        $staff = User::where('email', 'staff@example.com')->sole();

        $this->assertTrue($admin->hasRole(Role::Admin));
        $this->assertTrue($staff->hasRole(Role::Staff));
        $this->assertTrue($admin->is_active);
        $this->assertTrue($staff->is_active);
    }

    public function test_a_seeded_admin_can_sign_in_and_reach_administration(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post(route('login'), [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('admin.staff.index'))->assertOk();
        $this->get(route('admin.settings.edit'))->assertOk();
        $this->get(route('admin.reports.index'))->assertOk();
    }

    public function test_a_seeded_staff_member_can_sell_but_not_administer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post(route('login'), [
            'email' => 'staff@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('pos.index'))->assertOk();

        $this->get(route('admin.staff.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_the_seeder_creates_stock_to_sell(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Category::count());
        $this->assertGreaterThan(0, Product::where('is_active', true)->where('stock', '>', 0)->count());

        // Every seeded product must be sellable on the terminal, which means
        // a name, a sku, a price and a category.
        Product::where('is_active', true)->get()->each(function (Product $product): void {
            $this->assertNotEmpty($product->name);
            $this->assertNotEmpty($product->sku);
            $this->assertGreaterThan(0, (float) $product->price);
            $this->assertNotNull($product->category_id);
        });
    }

    public function test_seeded_skus_are_unique(): void
    {
        $this->seed(DatabaseSeeder::class);

        $skus = Product::pluck('sku');

        $this->assertSame($skus->count(), $skus->unique()->count());
    }

    public function test_the_catalogue_includes_a_low_stock_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Low stock warnings are a dashboard feature, so a seeded install
        // should demonstrate one rather than showing an empty state.
        $this->assertGreaterThan(0, Product::lowStock()->count());
    }

    public function test_seeded_settings_are_present(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertNotEmpty(Setting::get(Setting::BUSINESS_NAME));
        $this->assertNotEmpty(Setting::currency());
        $this->assertSame(0.0, Setting::taxRate());
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [
            'users' => User::count(),
            'categories' => Category::count(),
            'products' => Product::count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts['users'], User::count());
        $this->assertSame($counts['categories'], Category::count());
        $this->assertSame($counts['products'], Product::count());
    }

    public function test_reseeding_does_not_revert_an_administrators_settings(): void
    {
        $this->seed(DatabaseSeeder::class);
        Setting::put(Setting::BUSINESS_NAME, 'Edited By Owner');
        Setting::put(Setting::TAX_RATE, '9');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Edited By Owner', Setting::get(Setting::BUSINESS_NAME));
        $this->assertSame(9.0, Setting::taxRate());
    }

    public function test_reseeding_does_not_reset_a_repriced_product(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::first();
        $product->update(['price' => 99.00]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(99.00, (float) $product->fresh()->price);
    }

    public function test_the_settings_seeder_only_fills_gaps(): void
    {
        Setting::put(Setting::BUSINESS_NAME, 'Mine');

        $this->seed(SettingsSeeder::class);

        $this->assertSame('Mine', Setting::get(Setting::BUSINESS_NAME));
        $this->assertNotEmpty(Setting::get(Setting::CURRENCY_SYMBOL));
    }

    public function test_the_user_seeder_syncs_roles_and_permissions(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertSame(2, \Spatie\Permission\Models\Role::count());
        $this->assertSame(
            count(Permission::cases()),
            \Spatie\Permission\Models\Permission::count(),
        );
    }

    public function test_the_catalogue_seeder_can_run_on_its_own(): void
    {
        $this->seed(CatalogueSeeder::class);

        $this->assertGreaterThan(0, Product::count());
    }
}
