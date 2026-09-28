<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cola 500ml Can',
            'category_id' => null,
            'cost_price' => 0.65,
            'selling_price' => 1.50,
            'stock' => 24,
            'low_stock_threshold' => 6,
            'unit' => 'pcs',
            'is_active' => 1,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ], $overrides);
    }

    public function test_a_real_png_file_is_stored_byte_for_byte(): void
    {
        $admin = User::factory()->admin()->create();

        // A genuine 1x1 PNG, written without needing the GD extension.
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $source = tempnam(sys_get_temp_dir(), 'pos').'.png';
        file_put_contents($source, $bytes);

        try {
            $this->actingAs($admin)
                ->post(route('admin.products.store'), $this->payload([
                    'name' => 'Real Photo',
                    'image' => new UploadedFile($source, 'real.png', 'image/png', null, true),
                ]))
                ->assertSessionHasNoErrors();
        } finally {
            @unlink($source);
        }

        $product = Product::sole();

        Storage::disk('public')->assertExists($product->image_path);
        $this->assertSame(
            $bytes,
            Storage::disk('public')->get($product->image_path),
            'The stored photo must be the uploaded file, untouched.'
        );
        $this->assertSame(
            'image/png',
            Storage::disk('public')->mimeType($product->image_path),
            'The public disk must recognise the stored file as a PNG.'
        );
    }

    public function test_an_admin_can_upload_a_product_image(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('cola.jpg', 120, 'image/jpeg'),
            ]))
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $product = Product::sole();

        $this->assertTrue($product->hasImage());
        $this->assertStringStartsWith('products/cola-500ml-can-', $product->image_path);
        $this->assertStringEndsWith('.jpg', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_the_image_url_points_at_the_public_storage_path(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['name' => 'Juice 1L']);
        $product->forceFill(['image_path' => 'products/juice.png'])->save();

        $url = $product->imageUrl();

        $this->assertStringEndsWith('/storage/products/juice.png', $url);
        $this->assertNull(Product::where('name', 'Nothing')->first()?->imageUrl());
    }

    public function test_a_product_without_an_image_reports_none(): void
    {
        $product = Product::factory()->create();

        $this->assertFalse($product->hasImage());
        $this->assertNull($product->imageUrl());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('storage/products/', false);
    }

    public function test_png_and_webp_are_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['shot.png', 'shot.webp'] as $index => $name) {
            $this->actingAs($admin)
                ->post(route('admin.products.store'), $this->payload([
                    'name' => 'Photo '.$index,
                    'image' => UploadedFile::fake()->create($name, 40, str_ends_with($name, 'webp') ? 'image/webp' : 'image/png'),
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Product::whereNotNull('image_path')->count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Product::count());
        $this->assertEmpty(Storage::disk('public')->files('products'));
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                // 3 MB against a 2 MB limit.
                'image' => UploadedFile::fake()->create('huge.jpg', 3072, 'image/jpeg'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Product::count());
    }

    public function test_uploading_a_new_image_replaces_and_deletes_the_old_file(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'image' => UploadedFile::fake()->create('first.jpg', 90, 'image/jpeg'),
        ]));

        $product = Product::sole();
        $original = $product->image_path;

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => $product->name,
                'stock' => $product->stock,
                'image' => UploadedFile::fake()->create('second.jpg', 90, 'image/jpeg'),
            ]))
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertNotSame($original, $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        Storage::disk('public')->assertMissing($original);
        $this->assertCount(1, Storage::disk('public')->files('products'), 'The old photo must not linger.');
    }

    public function test_an_image_can_be_removed_without_uploading_a_new_one(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $product->forceFill(['image_path' => 'products/gone.png'])->save();
        Storage::disk('public')->put('products/gone.png', 'x');

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => $product->name,
                'stock' => $product->stock,
                'remove_image' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->hasImage());
        Storage::disk('public')->assertMissing('products/gone.png');
    }

    public function test_uploading_a_photo_and_ticking_remove_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $product->forceFill(['image_path' => 'products/keep.png'])->save();
        Storage::disk('public')->put('products/keep.png', 'x');

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => $product->name,
                'stock' => $product->stock,
                'remove_image' => 1,
                'image' => UploadedFile::fake()->create('new.jpg', 40, 'image/jpeg'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertTrue($product->fresh()->hasImage(), 'The existing photo must survive a rejected request.');
        Storage::disk('public')->assertExists('products/keep.png');
    }

    public function test_editing_without_touching_the_image_keeps_the_file(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $product->forceFill(['image_path' => 'products/stay.png'])->save();
        Storage::disk('public')->put('products/stay.png', 'x');

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => 'Renamed Item',
                'stock' => $product->stock,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('products/stay.png', $product->fresh()->image_path);
        Storage::disk('public')->assertExists('products/stay.png');
    }

    public function test_deleting_a_product_removes_its_image(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $product->forceFill(['image_path' => 'products/delete-me.png'])->save();
        Storage::disk('public')->put('products/delete-me.png', 'x');

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('products.index'));

        Storage::disk('public')->assertMissing('products/delete-me.png');
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_staff_cannot_upload_images(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('nope.jpg', 40, 'image/jpeg'),
            ]))
            ->assertRedirect(route('pos.index'));

        $this->assertSame(0, Product::count());
        $this->assertEmpty(Storage::disk('public')->files('products'));
    }

    public function test_the_forms_offer_the_upload_field(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('multipart/form-data', false)
            ->assertSee('name="image"', false)
            ->assertSee('Product Image');

        $this->actingAs($admin)
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('name="image"', false);

        $product->forceFill(['image_path' => 'products/existing.png'])->save();

        $this->actingAs($admin)
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Remove current image')
            ->assertSee('storage/products/existing.png', false);
    }

    public function test_the_till_search_returns_the_image_url(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Visible Photo']);
        $product->forceFill(['image_path' => 'products/till.png'])->save();

        $this->actingAs($cashier)
            ->getJson(route('pos.search', ['q' => 'Visible']))
            ->assertOk()
            ->assertJsonPath('data.0.image', fn ($url) => str_ends_with((string) $url, '/storage/products/till.png'));
    }
}
