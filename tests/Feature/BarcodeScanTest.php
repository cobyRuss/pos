<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scanned_barcode_resolves_to_its_product(): void
    {
        $cashier = User::factory()->staff()->create();
        $cola = Product::factory()->create([
            'name' => 'Cola 500ml Can',
            'barcode' => '4801101000014',
            'stock' => 10,
        ]);

        $this->actingAs($cashier)
            ->getJson(route('pos.lookup', ['barcode' => '4801101000014']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('product.id', $cola->id)
            ->assertJsonPath('product.name', 'Cola 500ml Can');
    }

    public function test_an_unknown_barcode_is_reported_not_thrown(): void
    {
        $cashier = User::factory()->staff()->create();

        // Scanning a product the store has never heard of is an ordinary event
        // at a till, not an error page: the message has to name the code and say
        // what to do next.
        $this->actingAs($cashier)
            ->getJson(route('pos.lookup', ['barcode' => '9999999999999']))
            ->assertStatus(422)
            ->assertJsonPath('found', false)
            ->assertJsonPath('barcode', '9999999999999');

        $this->assertStringContainsString(
            '9999999999999',
            (string) json_decode($this->actingAs($cashier)
                ->getJson(route('pos.lookup', ['barcode' => '9999999999999']))
                ->getContent(), true)['message'],
        );
    }

    public function test_a_product_without_a_barcode_is_never_returned_by_a_scan(): void
    {
        $cashier = User::factory()->staff()->create();
        Product::factory()->create(['name' => 'Loose Bananas', 'barcode' => null]);

        // Nothing is filed under a blank code, so a blank scan finds nothing.
        $this->actingAs($cashier)
            ->getJson(route('pos.lookup', ['barcode' => '0']))
            ->assertStatus(422)
            ->assertJsonPath('found', false);
    }

    public function test_a_non_numeric_scan_is_rejected_before_it_reaches_the_database(): void
    {
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->getJson(route('pos.lookup', ['barcode' => 'not-a-code']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_an_inactive_product_is_not_sellable_by_scanning(): void
    {
        $cashier = User::factory()->staff()->create();
        Product::factory()->create([
            'name' => 'Discontinued Can',
            'barcode' => '4801101000099',
            'is_active' => false,
        ]);

        $this->actingAs($cashier)
            ->getJson(route('pos.lookup', ['barcode' => '4801101000099']))
            ->assertStatus(422)
            ->assertJsonPath('found', false);
    }

    public function test_admins_cannot_use_the_till_lookup(): void
    {
        $admin = User::factory()->admin()->create();

        // The lookup is a till endpoint. The role middleware is what stops an
        // admin driving the till, and this route must sit behind it like the rest.
        $this->actingAs($admin)
            ->get(route('pos.lookup', ['barcode' => '4801101000014']))
            ->assertRedirect();
    }

    public function test_guests_cannot_reach_the_till_lookup(): void
    {
        $this->get(route('pos.lookup', ['barcode' => '4801101000014']))
            ->assertRedirect(route('login'));
    }

    public function test_the_till_offers_scanning_without_depending_on_it(): void
    {
        $cashier = User::factory()->staff()->create();
        $cola = Product::factory()->create(['name' => 'Cola Can']);

        $response = $this->actingAs($cashier)->get(route('pos.index'))->assertOk();

        // The scan button and its dialog are offered...
        $response->assertSee('id="open-scanner"', false)
            ->assertSee('id="scannerModal"', false);

        // ...and the name search and product grid are still right there, because
        // scanning is a shortcut and must never become the only way to sell.
        $response->assertSee('Product name or barcode', false)
            ->assertSee($cola->name);

        // The scanner library is vendored, not fetched from a CDN, so a store
        // with no connection can still open the page.
        $this->assertStringNotContainsString(
            'cdn.jsdelivr',
            (string) $response->getContent(),
        );
    }
}
