<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReceiveLotRequest;
use App\Http\Requests\Admin\UpdateLotRequest;
use App\Models\Product;
use App\Models\ProductLot;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\LotAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Batches for products that track expiry.
 *
 * Stock arrives here rather than through the plain "restock" form, because a
 * delivery carries a batch code and a date. Adjusting a batch's count is a
 * stock count for that batch specifically: a count that cannot state which
 * batch it saw would quietly destroy the expiry record.
 */
class LotController extends Controller
{
    public function __construct(
        private readonly LotAllocator $lots,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Product $product): View
    {
        abort_unless($request->user()->can(Permission::InventoryView->value), 403);

        return view('admin.lots.index', [
            'product' => $product->load('category'),
            'lots' => $product->lots()->orderByRaw('(expires_at IS NULL) ASC')->orderBy('expires_at')->orderBy('id')->get(),
            'expiringSoon' => $product->lots()->inStock()->expiringWithin(30)->count(),
            'expiredCount' => $product->lots()->inStock()->expired()->count(),
        ]);
    }

    public function create(Product $product): View
    {
        return view('admin.lots.create', ['product' => $product]);
    }

    /**
     * Receive a delivery into a batch, creating it or topping it up.
     */
    public function store(ReceiveLotRequest $request, Product $product): RedirectResponse
    {
        $quantity = (int) $request->input('quantity');

        try {
            // Create or refresh the batch metadata, then move stock into it.
            // Only the move changes the quantity, so the batch and the cached
            // product total cannot drift apart.
            $lot = $this->lots->batchFor(
                product: $product,
                code: $request->string('code')->toString(),
                expiresAt: $request->input('expires_at'),
                cost: $request->filled('cost') ? (float) $request->input('cost') : null,
                notes: $request->input('notes'),
            );

            $this->inventory->move(
                product: $product,
                quantityChange: $quantity,
                type: InventoryService::TYPE_IN,
                user: $request->user(),
                notes: "Received into batch {$lot->code}",
                lot: $lot,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->audit->record(
            AuditLogger::STOCK_ADJUSTED,
            $lot,
            $request->user()->getKey(),
            "Received {$request->input('quantity')} of [{$product->name}] into batch {$lot->code}.",
        );

        return redirect()
            ->route('admin.lots.index', $product)
            ->with('success', "Received {$request->input('quantity')} into batch [{$lot->code}].");
    }

    public function edit(Product $product, ProductLot $lot): View
    {
        $this->assertOwnedBy($product, $lot);

        return view('admin.lots.edit', ['product' => $product, 'lot' => $lot]);
    }

    /**
     * Correct a batch's count: the per-batch equivalent of a stock count.
     */
    public function update(UpdateLotRequest $request, Product $product, ProductLot $lot): RedirectResponse
    {
        $this->assertOwnedBy($product, $lot);

        $newQuantity = (int) $request->input('quantity');
        $difference = $newQuantity - (int) $lot->quantity;

        if ($difference === 0) {
            return back()->with('success', "Batch [{$lot->code}] already holds {$newQuantity}; nothing to change.");
        }

        try {
            // Metadata first, then move the difference. Only the movement
            // changes the quantity, so the batch and the cached product total
            // cannot drift apart.
            $lot->forceFill([
                'code' => $request->string('code')->toString(),
                'expires_at' => $request->input('expires_at'),
                'cost' => $request->filled('cost') ? (float) $request->input('cost') : $lot->cost,
                'notes' => $request->input('notes'),
            ])->save();

            $this->inventory->move(
                product: $product,
                quantityChange: $difference,
                type: InventoryService::TYPE_ADJUSTMENT,
                user: $request->user(),
                notes: "Count correction for batch {$lot->code}",
                lot: $lot,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->audit->record(
            AuditLogger::STOCK_ADJUSTED,
            $lot,
            $request->user()->getKey(),
            "Counted batch {$lot->code} of [{$product->name}] to {$newQuantity}.",
        );

        return redirect()
            ->route('admin.lots.index', $product)
            ->with('success', "Batch [{$lot->code}] set to {$newQuantity}.");
    }

    /**
     * A batch is only deletable once it is empty. Deleting a batch that still
     * holds stock would leave the units with nowhere to live and break the
     * link for any return in flight.
     */
    public function destroy(Request $request, Product $product, ProductLot $lot): RedirectResponse
    {
        $this->assertOwnedBy($product, $lot);

        if ($lot->quantity > 0) {
            return back()->with('error', "Batch [{$lot->code}] still holds {$lot->quantity} unit(s). Adjust it to zero first.");
        }

        $code = $lot->code;
        $lot->delete();

        $product->forceFill(['stock' => (int) $product->lots()->sum('quantity')])->save();

        $this->audit->record(
            AuditLogger::STOCK_ADJUSTED,
            $product,
            $request->user()->getKey(),
            "Deleted empty batch {$code} of [{$product->name}].",
        );

        return back()->with('success', "Batch [{$code}] deleted.");
    }

    /**
     * Guard against a lot id from a different product being smuggled in.
     */
    private function assertOwnedBy(Product $product, ProductLot $lot): void
    {
        abort_unless((int) $lot->product_id === (int) $product->getKey(), 404);
    }
}
