<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only product discovery, available to staff and admins.
 */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->active()
            ->with('category')
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->input('availability') === 'in_stock', fn ($query) => $query->where('stock', '>', 0))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::active()->orderBy('name')->get(),
            'filters' => $request->only(['search', 'category', 'availability']),
        ]);
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load('category');

        return view('products.show', [
            'product' => $product,
        ]);
    }
}
