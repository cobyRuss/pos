<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\CheckoutRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PosController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly OrderService $orders,
    ) {}

    public function index(Request $request): View
    {
        return view('pos.index', $this->cartViewData() + [
            'categories' => Category::active()->orderBy('name')->get(),
            'products' => $this->searchProducts($request),
        ]);
    }

    /**
     * Data the cart partials need.
     *
     * @return array<string, mixed>
     */
    private function cartViewData(): array
    {
        return [
            'cartItems' => $this->cart->items(),
            'totals' => $this->cart->totals(),
        ];
    }

    /**
     * Cart JSON payload, including the freshly rendered cart so the UI can swap
     * it without a full page reload.
     *
     * Only the line items are re-rendered. The totals panel is deliberately
     * left out: replacing it would destroy the "Take Payment" button, whose
     * click handler is bound once, and the cashier would be unable to check
     * out. The till updates the totals in place from `totals` instead.
     *
     * @return array<string, mixed>
     */
    private function cartPayload(string $message = ''): array
    {
        $data = $this->cartViewData();

        $payload = [
            'items' => $data['cartItems'],
            'totals' => $data['totals'],
            'items_html' => view('pos.partials.cart-items', $data)->render(),
        ];

        if ($message !== '') {
            $payload['message'] = $message;
        }

        return $payload;
    }

    /**
     * Product search shared by the POS grid and the live-search endpoint.
     *
     * @return Collection<int, Product>
     */
    private function searchProducts(Request $request)
    {
        $query = Product::query()
            ->active()
            ->with(['category', 'batches'])
            ->orderBy('name');

        if ($term = trim((string) $request->input('q'))) {
            $query->search($term);
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($request->boolean('in_stock')) {
            $query->inStock();
        }

        return $query->limit(60)->get();
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'in_stock' => ['nullable', 'boolean'],
        ]);

        $products = $this->searchProducts($request->merge($validated))->map(function (Product $product) {
            $soonest = $product->batches
                ->filter(fn (ProductBatch $b) => $b->hasExpiryDate() && (int) $b->quantity > 0)
                ->sortBy(fn (ProductBatch $b) => $b->expiry_date->timestamp)
                ->first();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->name,
                'image' => $product->imageUrl(),
                'price' => (float) $product->selling_price,
                'stock' => (int) $product->stock,
                'sellable_stock' => $product->sellableStock(),
                'expiry_label' => $soonest?->expiryLabel(),
                'unit' => $product->unit,
            ];
        });

        return response()->json([
            'data' => $products,
            'totals' => $this->cart->totals(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if (! $product->is_active) {
            return response()->json([
                'message' => sprintf('"%s" is not available for sale.', $product->name),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $quantity = (int) ($validated['quantity'] ?? 1);

        // Stock on hand includes lots that have passed their date. FEFO cannot
        // sell those, so the till must not either.
        $sellable = $product->sellableStock();

        if ($sellable < $quantity) {
            $expired = $product->expiredQuantity();

            $message = match (true) {
                $sellable === 0 && $expired > 0 => sprintf(
                    'All %d unit(s) of "%s" are past their expiry date and cannot be sold.',
                    $expired,
                    $product->name,
                ),
                $expired > 0 => sprintf(
                    'Only %d of %d unit(s) of "%s" can be sold - the rest are past their expiry date.',
                    $sellable,
                    (int) $product->stock,
                    $product->name,
                ),
                default => sprintf(
                    'Only %d unit(s) of "%s" left in stock.',
                    $sellable,
                    $product->name,
                ),
            };

            return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->cart->add($product, $quantity);

        return response()->json($this->cartPayload(sprintf('%s added to cart.', $product->name)));
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $this->cart->updateQuantity($product, (int) $validated['quantity']);

        return response()->json($this->cartPayload());
    }

    public function destroy(int $product): JsonResponse
    {
        $this->cart->remove($product);

        return response()->json($this->cartPayload('Item removed from cart.'));
    }

    public function clear(): JsonResponse
    {
        $this->cart->clear();

        return response()->json($this->cartPayload('Cart cleared.'));
    }

    public function checkout(CheckoutRequest $request): RedirectResponse
    {
        $issues = $request->stockIssues();

        if ($issues !== []) {
            return back()
                ->withInput()
                ->with('error', 'Some items are no longer available: '.implode('; ', $issues));
        }

        $cart = $this->cart->raw();

        try {
            $order = $this->orders->checkout($cart, $request->validated(), $request->user());
        } catch (InsufficientStockException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        $this->cart->clear();

        return redirect()
            ->route('orders.receipt', $order)
            ->with('success', sprintf('Sale completed - order %s.', $order->order_number));
    }
}
