<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Pos\AddToCartRequest;
use App\Http\Requests\Pos\ApplyDiscountRequest;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Http\Requests\Pos\UpdateCartItemRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class PosController extends Controller
{
    public function __construct(
        private readonly Cart $cart,
        private readonly CheckoutService $checkout,
    ) {
        // pos.access and pos.create-order are enforced on the route group and
        // in the form requests; the base Controller has no middleware() helper.
    }

    /**
     * The terminal: product picker on one side, the live basket on the other.
     */
    public function index(Request $request): View
    {
        $products = Product::query()
            ->active()
            ->when($request->filled('q'), fn ($query) => $query->search($request->string('q')->toString()))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('pos.index', [
            'products' => $products,
            'categories' => $this->activeCategories(),
            'taxRate' => Setting::taxRate(),
        ]);
    }

    public function store(AddToCartRequest $request): RedirectResponse
    {
        $product = Product::findOrFail($request->integer('product_id'));

        $this->cart->add($product, (int) ($request->input('quantity') ?? 1));

        return back()->with('success', "[{$product->name}] added to the cart.");
    }

    public function update(UpdateCartItemRequest $request): RedirectResponse
    {
        $product = Product::findOrFail($request->integer('product_id'));
        $quantity = (int) $request->input('quantity');

        $this->cart->setQuantity($product, $quantity);

        $message = $quantity === 0
            ? "[{$product->name}] removed from the cart."
            : "[{$product->name}] quantity set to {$quantity}.";

        return back()->with('success', $message);
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->cart->remove((int) $product->getKey());

        return back()->with('success', "[{$product->name}] removed from the cart.");
    }

    public function discount(ApplyDiscountRequest $request): RedirectResponse
    {
        $type = (string) $request->input('discount_type');

        if ($type === Cart::DISCOUNT_NONE) {
            $this->cart->clearDiscount();
        } else {
            $this->cart->applyDiscount($type, (float) $request->input('discount_value'));
        }

        return back()->with('success', 'Discount updated.');
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();

        return back()->with('success', 'Cart cleared.');
    }

    /**
     * Complete the sale. The cart is emptied by the service only once the
     * order is safely committed, so a failure leaves the basket intact for
     * the cashier to retry.
     */
    public function checkout(CheckoutRequest $request): RedirectResponse
    {
        // Only someone holding the permission can authorise selling past-date
        // stock, so a cashier cannot wave an expired croissant through.
        $allowExpired = $request->boolean('allow_expired')
            && $request->user()->can(Permission::SellExpiredStock->value);

        try {
            $order = $this->checkout->checkout($request->user(), $request->validated(), $allowExpired);
        } catch (RuntimeException $e) {
            // A business rule failure, not a bug: show it and keep the cart.
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Checkout failed', ['exception' => $e]);

            return back()
                ->withInput()
                ->with('error', 'The sale could not be completed. Nothing was charged; please try again.');
        }

        return redirect()
            ->route('pos.receipt', $order)
            ->with('success', "Sale {$order->order_number} completed.");
    }

    /**
     * Printable 80mm receipt.
     */
    public function receipt(Request $request, Order $order): View|RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        return view('pos.receipt', [
            'order' => $order->load(['items', 'user']),
            'business' => Setting::allCached(),
            'currency' => Setting::currency(),
        ]);
    }

    /**
     * Staff may only see receipts for their own sales.
     */
    private function authorizeOrder(Request $request, Order $order): void
    {
        $isOwner = (int) $order->user_id === (int) $request->user()->getKey();
        $canSeeAll = $request->user()->can(Permission::OrderViewAll->value);

        abort_unless($isOwner || $canSeeAll, 403);
    }

    /**
     * @return Collection<int, Category>
     */
    private function activeCategories(): Collection
    {
        return Category::query()
            ->active()
            ->orderBy('name')
            ->get();
    }
}
