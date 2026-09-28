<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\OrderStateException;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $query = Order::query();

        // Staff are scoped to their own sales; admins see everyone.
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->getAuthIdentifier());
        } elseif ($request->boolean('mine')) {
            $query->where('user_id', $request->user()->getAuthIdentifier());
        }

        $query->search($validated['q'] ?? null);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        $this->applyDateRange($query, $validated['from'] ?? null, $validated['to'] ?? null);

        // Totals run on the plain filtered query: layering aggregates onto the
        // listing (orders.* + withCount) breaks on MySQL without a GROUP BY.
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COALESCE(SUM(total), 0) as total_sum, COALESCE(SUM(refunded_amount), 0) as refund_sum, COUNT(*) as order_count')
            ->first();

        $orders = $query->with('user')->withCount('items')->latest()->paginate(15)->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'filters' => $validated,
            'statuses' => collect(OrderStatus::cases())->mapWithKeys(
                fn (OrderStatus $status) => [$status->value => $status->label()]
            )->all(),
            'summary' => [
                'count' => (int) $summary->order_count,
                'gross' => (float) $summary->total_sum,
                'refunded' => (float) $summary->refund_sum,
                'net' => round((float) $summary->total_sum - (float) $summary->refund_sum, 2),
            ],
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorizeView($request, $order);

        return view('orders.show', [
            'order' => $order->load(['items.product', 'user', 'refunds.items', 'refunds.user']),
        ]);
    }

    public function receipt(Request $request, Order $order): View
    {
        $this->authorizeView($request, $order);

        return view('pos.receipt', [
            'order' => $order->load(['items', 'user', 'refunds']),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeView($request, $order);

        $validated = $request->validate([
            'customer_note' => ['nullable', 'string', 'max:255'],
        ]);

        $order->update($validated);

        return back()->with('success', 'Order note updated.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->orders->cancel($order, $validated['reason'], $request->user());
        } catch (OrderStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Order %s cancelled and stock returned to inventory.',
            $order->order_number,
        ));
    }

    /**
     * Staff may only touch their own orders; admins may touch any order.
     */
    private function authorizeView(Request $request, Order $order): void
    {
        $user = $request->user();

        abort_if(
            ! $user->isAdmin() && $order->user_id !== $user->getAuthIdentifier(),
            Response::HTTP_FORBIDDEN,
            'You can only view your own orders.',
        );
    }

    private function applyDateRange($query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
    }
}
