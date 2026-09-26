<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $products = Product::query()
            ->with('category')
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->filled('status'), function ($query) use ($request) {
                match ($request->string('status')->toString()) {
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false),
                    'low_stock' => $query->lowStock(),
                    'out_of_stock' => $query->where('stock', '<=', 0),
                    default => null,
                };
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'filters' => $request->only(['search', 'category', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', [
            'product' => new Product,
            // Retired categories are not offered for new products.
            'categories' => Category::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            // Stock is applied through the inventory service so the opening
            // quantity is logged; seeding it here as well would double it.
            $data = $request->safe()->except(['image', 'stock']);

            $product = Product::create([
                ...$data,
                'stock' => 0,
                'is_active' => $request->boolean('is_active'),
            ]);

            if ($request->hasFile('image')) {
                $product->forceFill([
                    'image' => $request->file('image')->store('products', 'public'),
                ])->save();
            }

            $openingStock = (int) $request->validated('stock');

            if ($openingStock > 0) {
                $this->inventory->move(
                    product: $product,
                    quantityChange: $openingStock,
                    type: InventoryService::TYPE_IN,
                    user: $request->user(),
                    notes: 'Opening stock',
                );
            }

            return $product;
        });

        $this->audit->record(
            action: 'product.created',
            subject: $product,
            userId: $request->user()->id,
            description: "Created product [{$product->name}] (SKU {$product->sku})",
        );

        return redirect()
            ->route('admin.products.index')
            ->with('success', "Product [{$product->name}] created.");
    }

    public function edit(Product $product): View
    {
        $product->load('category');

        return view('admin.products.edit', [
            'product' => $product,
            // The product's own category stays selectable even if it has since
            // been retired, so saving does not silently clear the link.
            'categories' => Category::active()
                ->orWhere('id', $product->category_id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $originalStock = (int) $product->stock;

        DB::transaction(function () use ($request, $product, $originalStock) {
            $product->fill([
                ...$request->safe()->except([
                    'image', 'remove_image', 'stock', 'is_active',
                    'tracks_expiry', 'expiry_warning_days',
                ]),
                // An unchecked checkbox is absent from the payload, so the
                // flags have to be resolved explicitly.
                'is_active' => $request->boolean('is_active'),
                'tracks_expiry' => $request->boolean('tracks_expiry'),
                'expiry_warning_days' => $request->integer(
                    'expiry_warning_days',
                    Product::DEFAULT_EXPIRY_WARNING_DAYS,
                ),
            ]);

            if ($request->boolean('remove_image') && $product->image) {
                Storage::disk('public')->delete($product->image);
                $product->forceFill(['image' => null])->save();
            }

            if ($request->hasFile('image')) {
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }

                $product->forceFill([
                    'image' => $request->file('image')->store('products', 'public'),
                ])->save();
            }

            $product->save();

            $newStock = (int) $request->validated('stock');

            if ($newStock !== $originalStock) {
                $this->inventory->setLevel(
                    product: $product,
                    newStock: $newStock,
                    user: $request->user(),
                    notes: 'Stock level edited on the product form',
                );
            }
        });

        $this->audit->record(
            action: 'product.updated',
            subject: $product,
            userId: $request->user()->id,
            description: "Updated product [{$product->name}] (SKU {$product->sku})",
        );

        return redirect()
            ->route('admin.products.index')
            ->with('success', "Product [{$product->name}] updated.");
    }

    /**
     * Products are deactivated rather than deleted: order history and the
     * inventory log both reference them.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        if ($product->stock > 0) {
            return back()->with('error', "Cannot archive [{$product->name}] while stock remains. Adjust the stock to zero first.");
        }

        DB::transaction(function () use ($product) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $product->forceFill(['is_active' => false])->save();
        });

        $this->audit->record(
            action: 'product.archived',
            subject: $product,
            userId: $request->user()->id,
            description: "Archived product [{$product->name}] (SKU {$product->sku})",
        );

        return redirect()
            ->route('admin.products.index')
            ->with('success', "Product [{$product->name}] archived.");
    }
}
