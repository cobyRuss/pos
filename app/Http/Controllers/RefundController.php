<?php

namespace App\Http\Controllers;

use App\Http\Requests\Orders\RefundAllRequest;
use App\Http\Requests\Orders\RefundOrderRequest;
use App\Models\Order;
use App\Models\Refund;
use App\Services\AuditLogger;
use App\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.refunds.index', [
            'refunds' => Refund::query()
                ->with(['order', 'user', 'items'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Refund $refund): View
    {
        return view('admin.refunds.show', [
            'refund' => $refund->load(['order.user', 'user', 'items']),
        ]);
    }

    /**
     * Record a return against an order and pay the customer back.
     */
    public function store(RefundOrderRequest $request, Order $order): RedirectResponse
    {
        try {
            $refund = $this->refunds->refund(
                order: $order,
                actor: $request->user(),
                lines: $request->input('items'),
                method: $request->string('method')->toString(),
                reason: $request->input('reason'),
                referenceNo: $request->input('reference_no'),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Refund failed', ['order' => $order->getKey(), 'exception' => $e]);

            return back()->withInput()->with('error', 'The refund could not be processed. Nothing was changed.');
        }

        $this->audit->record(
            AuditLogger::REFUND_PROCESSED,
            $refund,
            $request->user()->getKey(),
            sprintf(
                'Refunded %s of order %s (%s).',
                $refund->total_amount,
                $order->order_number,
                $refund->method,
            ),
        );

        return redirect()
            ->route('admin.refunds.show', $refund)
            ->with('success', "Refund {$refund->id} recorded for order {$order->order_number}.");
    }

    /**
     * Return every remaining unit on an order in a single action.
     */
    public function storeAll(RefundAllRequest $request, Order $order): RedirectResponse
    {
        try {
            $refund = $this->refunds->refundAll(
                order: $order,
                actor: $request->user(),
                method: $request->string('method')->toString(),
                reason: $request->input('reason'),
                referenceNo: $request->input('reference_no'),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Full refund failed', ['order' => $order->getKey(), 'exception' => $e]);

            return back()->withInput()->with('error', 'The refund could not be processed. Nothing was changed.');
        }

        $this->audit->record(
            AuditLogger::REFUND_PROCESSED,
            $refund,
            $request->user()->getKey(),
            sprintf(
                'Fully refunded order %s (%s): %s.',
                $order->order_number,
                $refund->method,
                $refund->total_amount,
            ),
        );

        return redirect()
            ->route('admin.refunds.show', $refund)
            ->with('success', "Order {$order->order_number} fully refunded.");
    }
}
