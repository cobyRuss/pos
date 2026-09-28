<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'low', 'out'])],
            'sort' => ['nullable', Rule::in(['name', 'stock', 'price', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = Product::query()->with('category');

        $query->search($validated['q'] ?? null);

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        match ($validated['status'] ?? null) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'low' => $query->lowStock(),
            'out' => $query->where('stock', '<=', 0),
            default => null,
        };

        $sort = $validated['sort'] ?? 'name';
        $query->orderBy($sort, ($validated['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc');

        $products = $query->paginate(15)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'filters' => $validated,
        ]);
    }

    public function show(Product $product): View
    {
        $product->load(['category', 'batches']);

        // The lot holding stock that expires soonest, expired included - it is
        // the deadline the shop actually has to act on.
        $soonestBatch = $product->batches
            ->filter(fn (ProductBatch $batch) => $batch->hasExpiryDate() && (int) $batch->quantity > 0)
            ->sortBy(fn (ProductBatch $batch) => $batch->expiry_date->timestamp)
            ->first();

        return view('products.show', [
            'product' => $product,
            'soonestBatch' => $soonestBatch,
            'expiringCount' => $product->batches->filter(fn (ProductBatch $b) => $b->status() === 'expiring')->count(),
            'expiredCount' => $product->batches->filter(fn (ProductBatch $b) => $b->status() === 'expired')->count(),
            'movements' => $product->inventoryMovements()->with(['user', 'batch'])->latest()->limit(20)->get(),
            'sales' => $product->orderItems()
                ->whereHas('order', fn (Builder $q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->with('order')
                ->latest()
                ->limit(20)
                ->get(),
            'totalsSold' => (int) $product->orderItems()
                ->whereHas('order', fn (Builder $q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->sum('quantity'),
            'revenue' => (float) $product->orderItems()
                ->whereHas('order', fn (Builder $q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->sum('line_total'),
        ]);
    }

    public function create(): View
    {
        return view('products.create', [
            'product' => new Product(['is_active' => true, 'low_stock_threshold' => 5, 'unit' => 'pcs']),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $openingStock = (int) ($data['stock'] ?? 0);
        $expiryDate = $data['expiry_date'];

        // expiry_date belongs to the opening lot, not the product row.
        unset($data['expiry_date']);

        $product = DB::transaction(function () use ($request, $data, $openingStock, $expiryDate) {
            // Start at zero so the opening quantity is applied - and logged - once.
            $product = Product::create(array_merge($data, ['stock' => 0]));

            if ($openingStock > 0) {
                // The opening stock becomes the first delivery lot, carrying the
                // expiry date from the form.
                app(InventoryService::class)->increase(
                    $product,
                    $openingStock,
                    InventoryMovementType::StockIn,
                    'Opening stock',
                    null,
                    $request->user(),
                    null,
                    ['expiry_date' => $expiryDate],
                );
            }

            $this->syncImage($product, $request);

            return $product;
        });

        AuditLogger::record(
            AuditLogger::PRODUCT_CREATED,
            sprintf('Created product "%s".', $product->name),
            $product,
            null,
            $product->only(['name', 'selling_price', 'stock']),
        );

        return redirect()
            ->route('products.index')
            ->with('success', sprintf('Product "%s" created.', $product->name));
    }

    public function edit(Product $product): View
    {
        return view('products.edit', [
            'product' => $product->load('batches'),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $before = $product->only(['name', 'category_id', 'cost_price', 'selling_price', 'low_stock_threshold', 'unit', 'is_active']);
        $data = $request->validated();
        $newStock = (int) $data['stock'];

        // Stock is deliberately left out of the mass assignment so the change
        // flows through InventoryService and keeps a before/after trail.
        unset($data['stock']);

        // Expiry dates live on the delivery lots, and the edit form does not
        // offer one: a product can hold several lots with different dates.
        unset($data['expiry_date']);

        DB::transaction(function () use ($product, $data, $newStock, $request) {
            $stockChanged = $newStock !== (int) $product->stock;

            // image_path is owned by syncImage(), never by mass assignment.
            unset($data['image_path']);

            $product->fill($data)->save();

            if ($stockChanged) {
                app(InventoryService::class)->setStock(
                    $product->fresh(),
                    $newStock,
                    'Stock level updated on product edit',
                    $request->user(),
                );
            }

            $this->syncImage($product, $request);
        });

        $product->refresh();
        $diff = AuditLogger::diff($before, $product->only(array_keys($before)));

        AuditLogger::record(
            AuditLogger::PRODUCT_UPDATED,
            sprintf('Updated product "%s".', $product->name),
            $product,
            $diff['old'],
            $diff['new'],
        );

        return redirect()
            ->route('products.index')
            ->with('success', sprintf('Product "%s" updated.', $product->name));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;

        if ($product->orderItems()->exists()) {
            return back()->with('error', sprintf(
                'Cannot delete "%s" because it appears in past orders. Deactivate it instead to keep sales history intact.',
                $name,
            ));
        }

        AuditLogger::record(
            AuditLogger::PRODUCT_DELETED,
            sprintf('Deleted product "%s".', $name),
            $product,
            $product->only(['name', 'selling_price', 'stock']),
        );

        $product->deleteImage();
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('success', sprintf('Product "%s" deleted.', $name));
    }

    /**
     * Apply the uploaded photo (or the remove request) to a product.
     *
     * Replacing a photo deletes the old file so the public disk does not
     * accumulate orphans. Filenames are slugged from the product name with a
     * short random suffix, so "Cola Can" and "Cola Can 2L" cannot overwrite
     * each other's picture.
     */
    private function syncImage(Product $product, ProductRequest $request): void
    {
        if ($request->boolean('remove_image')) {
            $product->deleteImage();

            return;
        }

        if (! $request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');
        $previous = $product->image_path;

        $name = Str::slug($product->name) ?: 'product';
        $path = $file->storeAs('products', $name.'-'.Str::lower(Str::random(6)).'.'.$file->guessExtension(), 'public');

        $product->forceFill(['image_path' => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
    }
}
