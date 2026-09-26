<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\LotAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receiving a delivery into a batch, and counting a batch.
 *
 * A stock count that cannot state which batch it saw would quietly destroy the
 * expiry record, so counts here are per batch.
 */
class LotManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);
    }

    private function tracked(): Product
    {
        return Product::factory()->create([
            'price' => 5.00,
            'stock' => 0,
            'tracks_expiry' => true,
        ]);
    }

    /**
     * Receive a batch holding stock, the way a delivery arrives.
     */
    private function batch(Product $product, string $code, int $quantity, ?string $expiresAt = null, ?float $cost = null): ProductLot
    {
        return app(LotAllocator::class)->receive($product, $code, $quantity, $expiresAt, $cost);
    }

    public function test_receiving_a_delivery_creates_a_batch(): void
    {
        $product = $this->tracked();

        $this->actingAs($this->admin)
            ->post(route('admin.lots.store', $product), [
                'code' => 'DLV-4471',
                'quantity' => 12,
                'expires_at' => today()->addDays(10)->toDateString(),
                'cost' => 2.50,
            ])
            ->assertRedirect(route('admin.lots.index', $product))
            ->assertSessionHas('success');

        $lot = $product->lots()->sole();
        $this->assertSame('DLV-4471', $lot->code);
        $this->assertSame(12, $lot->quantity);
        $this->assertSame('2.50', $lot->cost);
        $this->assertSame(12, $product->fresh()->stock, 'The cached product total should follow the batch.');
    }

    public function test_receiving_into_an_existing_batch_tops_it_up(): void
    {
        $product = $this->tracked();

        $this->batch($product, 'DLV-1', 5, today()->addWeek()->toDateString(), 2.00);

        $this->actingAs($this->admin)
            ->post(route('admin.lots.store', $product), [
                'code' => 'DLV-1',
                'quantity' => 3,
            ])
            ->assertSessionHas('success');

        $lot = $product->lots()->sole();
        $this->assertSame(8, $lot->quantity);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_a_tracked_product_refuses_a_batchless_movement(): void
    {
        $product = $this->tracked();

        $this->expectExceptionMessage('must name the batch');

        app(InventoryService::class)->move(
            product: $product,
            quantityChange: 1,
            type: InventoryService::TYPE_IN,
        );
    }

    public function test_counting_a_batch_corrects_only_that_batch(): void
    {
        $product = $this->tracked();
        $a = $this->batch($product, 'A', 10, today()->addWeek()->toDateString(), 2.00);
        $b = $this->batch($product, 'B', 8, today()->addMonth()->toDateString(), 2.00);
        $product->update(['stock' => 18]);

        $this->actingAs($this->admin)
            ->put(route('admin.lots.update', [$product, $a]), [
                'code' => 'A',
                'quantity' => 7, // counted 3 fewer
            ])
            ->assertSessionHas('success');

        $this->assertSame(7, $a->fresh()->quantity);
        $this->assertSame(8, $b->fresh()->quantity, 'The other batch must be untouched.');
        $this->assertSame(15, $product->fresh()->stock);

        $log = InventoryLog::where('lot_id', $a->id)->where('type', 'adjustment')->sole();
        $this->assertSame(-3, $log->quantity_change);
    }

    public function test_counting_to_the_same_number_is_a_no_op(): void
    {
        $product = $this->tracked();
        $lot = $this->batch($product, 'A', 5, null, 1.00);
        $product->update(['stock' => 5]);

        $this->actingAs($this->admin)
            ->put(route('admin.lots.update', [$product, $lot]), ['code' => 'A', 'quantity' => 5])
            ->assertSessionHas('success');

        $this->assertSame(0, InventoryLog::where('type', 'adjustment')->count());
    }

    public function test_a_batch_with_stock_cannot_be_deleted(): void
    {
        $product = $this->tracked();
        $lot = $this->batch($product, 'A', 5, null, 1.00);
        $product->update(['stock' => 5]);

        $this->actingAs($this->admin)
            ->delete(route('admin.lots.destroy', [$product, $lot]))
            ->assertSessionHas('error');

        $this->assertNotNull($lot->fresh());
    }

    public function test_an_empty_batch_can_be_deleted(): void
    {
        $product = $this->tracked();
        $lot = $this->batch($product, 'A', 5, null, 1.00);
        $lot->update(['quantity' => 0]);
        $product->update(['stock' => 0]);

        $this->actingAs($this->admin)
            ->delete(route('admin.lots.destroy', [$product, $lot]))
            ->assertSessionHas('success');

        $this->assertNull($lot->fresh());
    }

    public function test_a_batch_from_another_product_is_rejected(): void
    {
        $mine = $this->tracked();
        $other = $this->tracked();
        $theirs = app(LotAllocator::class)->receive($other, 'X', 5, null, 1.00);

        $this->actingAs($this->admin)
            ->get(route('admin.lots.edit', [$mine, $theirs]))
            ->assertNotFound();
    }

    public function test_a_product_that_does_not_track_expiry_refuses_a_batch(): void
    {
        $product = Product::factory()->create(['tracks_expiry' => false, 'stock' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.lots.store', $product), ['code' => 'DLV-1', 'quantity' => 5])
            ->assertSessionHasErrors('code');
    }

    public function test_staff_cannot_manage_batches(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);
        $product = $this->tracked();

        $this->actingAs($staff)
            ->get(route('admin.lots.index', $product))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_the_batch_screens_render(): void
    {
        $product = $this->tracked();
        $this->batch($product, 'A', 5, today()->addDays(3)->toDateString(), 1.00);
        $product->update(['stock' => 5]);

        $this->actingAs($this->admin)->get(route('admin.lots.index', $product))->assertOk()->assertSee('A');
        $this->actingAs($this->admin)->get(route('admin.lots.create', $product))->assertOk();
    }

    public function test_an_expired_batch_shows_on_the_index(): void
    {
        $product = $this->tracked();
        $this->batch($product, 'OLD', 3, today()->subDay()->toDateString(), 1.00);
        $product->update(['stock' => 3]);

        $this->actingAs($this->admin)
            ->get(route('admin.lots.index', $product))
            ->assertOk()
            ->assertSee('Expired');
    }
}
