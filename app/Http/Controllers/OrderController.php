<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Orders\CancelOrderRequest;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\OrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderCancellationService $canceller,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sales history. Staff see their own till; anyone with orders.view-all
     * sees the whole shop.
     */
    public function index(Request $request): View
    {
        $this->middlewareAuthorize($request, Permission::OrderView);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'digital'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $canSeeAll = $request->user()->can(Permission::OrderViewAll->value);

        $orders = Order::query()
            ->with('user')
            ->withSum('items', 'quantity')
            ->when(! $canSeeAll, fn ($query) => $query->forUser((int) $request->user()->getKey()))
            ->when($filters['q'] ?? null, function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('order_number', 'like', $term)
                        ->orWhere('walkin_customer_name', 'like', $term)
                        ->orWhere('reference_no', 'like', $term);
                });
            })
            ->when($filters['status'] ?? null, fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['payment_method'] ?? null, fn ($query) => $query->where('payment_method', $filters['payment_method']))
            ->when($filters['from'] ?? null, fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] ?? null, fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'canSeeAll' => $canSeeAll,
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        $this->middlewareAuthorize($request, Permission::OrderView);

        $this->ensureVisible($request, $order);

        return view('orders.show', [
            'order' => $order->load(['items.product', 'user', 'cancelledBy']),
            'canCancel' => $request->user()->can(Permission::OrderCancel->value),
        ]);
    }

    /**
     * Cancel a completed sale and return its stock to the shelf.
     */
    public function cancel(CancelOrderRequest $request, Order $order): RedirectResponse
    {
        try {
            $cancelled = $this->canceller->cancel(
                $order,
                $request->user(),
                $request->string('cancel_reason')->toString(),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Order cancellation failed', ['order' => $order->getKey(), 'exception' => $e]);

            return back()->with('error', 'The order could not be cancelled. No changes were made.');
        }

        $this->audit->record(
            AuditLogger::ORDER_CANCELLED,
            $cancelled,
            $request->user()->getKey(),
            "Cancelled order {$cancelled->order_number}: {$request->string('cancel_reason')}",
        );

        return redirect()
            ->route('orders.show', $cancelled)
            ->with('success', "Order {$cancelled->order_number} cancelled and stock restored.");
    }

    /**
     * A staff member may only open their own orders; the permission check
     * alone would let any staff read every till's takings.
     */
    private function ensureVisible(Request $request, Order $order): void
    {
        $isOwner = (int) $order->user_id === (int) $request->user()->getKey();

        abort_unless($isOwner || $request->user()->can(Permission::OrderViewAll->value), 403);
    }

    private function middlewareAuthorize(Request $request, Permission $permission): void
    {
        abort_unless($request->user()?->can($permission->value), 403);
    }
}
