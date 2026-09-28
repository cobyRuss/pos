<?php

namespace App\Http\Controllers;

use App\Enums\RefundReason;
use App\Exceptions\OrderStateException;
use App\Exceptions\RefundPolicyException;
use App\Http\Requests\RefundRequest;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\RefundService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:unreviewed,reviewed'],
            'reason_code' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Refund::query()->with(['order', 'user', 'items', 'notifications']);

        if ($term = trim((string) ($validated['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q
                ->where('refund_number', 'like', $like)
                ->orWhere('reason', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', $like))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
        }

        if (! empty($validated['from'])) {
            $query->whereDate('refunded_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('refunded_at', '<=', $validated['to']);
        }

        if (($validated['status'] ?? '') === 'unreviewed') {
            $query->outstanding();
        } elseif (($validated['status'] ?? '') === 'reviewed') {
            $query->whereNotNull('reviewed_at');
        }

        if (! empty($validated['reason_code'])) {
            $query->where('reason_code', $validated['reason_code']);
        }

        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->first();

        return view('refunds.index', [
            'refunds' => $query->latest('refunded_at')->paginate(15)->withQueryString(),
            'filters' => $validated,
            'totalRefunded' => (float) $summary->total,
            'refundCount' => (int) $summary->count,
            'outstandingCount' => Refund::query()->outstanding()->count(),
            'undeliveredAlerts' => Refund::query()
                ->whereHas('notifications', fn ($q) => $q->where('status', '!=', 'sent'))
                ->count(),
            'reasons' => RefundReason::options(),
        ]);
    }

    public function create(Request $request, Order $order): View
    {
        abort_if($order->isCancelled(), 404, 'Cancelled orders cannot be refunded.');

        $refundableItems = $order->items
            ->filter(fn ($item) => $item->refundable_quantity > 0)
            ->values();

        return view('refunds.create', [
            'order' => $order->load(['items.product', 'user']),
            'refundableItems' => $refundableItems,
            'refundTotal' => (float) $order->refunded_amount,
            'reasons' => RefundReason::options(),
            'reviewThreshold' => Setting::refundReviewThreshold(),
            'dailyLimit' => Setting::refundDailyLimit(),
            'spentToday' => $this->spentToday($request),
            // Minted fresh on every render, so a double-click within one page
            // view carries the same key and collapses onto the one refund. A
            // validation failure re-renders with a new key, which is correct:
            // the request was rejected, so the next submit is a new intent.
            'idempotencyKey' => Str::random(40),
        ]);
    }

    public function store(RefundRequest $request, Order $order): RedirectResponse
    {
        try {
            $refund = $this->refunds->refund(
                $order,
                $request->quantities(),
                RefundReason::from($request->validated('reason_code')),
                $request->validated('reason'),
                $request->validated('method'),
                $request->validated('note'),
                $request->user(),
                // A retried or double-clicked submit collapses back onto the
                // refund that already exists instead of paying out twice.
                $request->validated('idempotency_key'),
            );
        } catch (OrderStateException|RefundPolicyException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = sprintf(
            'Refund %s processed for %s. Stock has been restored.',
            $refund->refund_number,
            Setting::money($refund->amount),
        );

        if ($refund->review_required) {
            $message .= sprintf(
                ' This is over the %s review threshold, so the owner has been alerted and it is flagged in the refunds report.',
                Setting::money(Setting::refundReviewThreshold()),
            );
        }

        return redirect()
            ->route('refunds.show', $refund)
            ->with('success', $message);
    }

    public function show(Request $request, Refund $refund): View
    {
        $refund->load(['items.product', 'user', 'reviewer', 'order.items', 'notifications']);

        // A cashier may look at their own refunds - they need the slip - but
        // another cashier's payouts are the owner's business, not theirs.
        abort_if(
            ! $request->user()->isAdmin() && $refund->user_id !== $request->user()->getAuthIdentifier(),
            403,
            'You can only view refunds you processed yourself.',
        );

        return view('refunds.show', ['refund' => $refund]);
    }

    /**
     * Sign off a high-value refund. Admin only.
     */
    public function review(Request $request, Refund $refund): RedirectResponse
    {
        abort_if($refund->isReviewed(), 422, 'This refund has already been reviewed.');

        $refund->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->getAuthIdentifier(),
        ])->save();

        $refund->loadMissing('order');

        AuditLogger::record(
            AuditLogger::REFUND_REVIEWED,
            sprintf(
                '%s reviewed refund %s for order %s (%s).',
                $request->user()->name,
                $refund->refund_number,
                $refund->order?->order_number ?? 'unknown',
                Setting::money($refund->amount),
            ),
            $refund,
            ['reviewed_at' => null],
            ['reviewed_by' => $request->user()->name],
        );

        return back()->with('success', sprintf('Refund %s marked as reviewed.', $refund->refund_number));
    }

    /**
     * What this cashier has already refunded today, against the daily ceiling.
     */
    private function spentToday(Request $request): float
    {
        return (float) Refund::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->whereDate('refunded_at', now()->toDateString())
            ->sum('amount');
    }
}
