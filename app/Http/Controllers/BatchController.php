<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Http\Requests\BatchRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The delivery lots behind a product's stock figure.
 *
 * Stock movements themselves stay on the adjust screen, which is the one place
 * allowed to change quantities. This controller records the lots and keeps
 * their expiry dates honest.
 */
class BatchController extends Controller
{
    public function index(Product $product): View
    {
        $product->load('category');

        $batches = $product->batches()->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')->get();

        return view('batches.index', [
            'product' => $product,
            'batches' => $batches,
            'expiringCount' => $batches->filter(fn (ProductBatch $b) => $b->status() === 'expiring')->count(),
            'expiredCount' => $batches->filter(fn (ProductBatch $b) => $b->status() === 'expired')->count(),
        ]);
    }

    public function store(BatchRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $quantity = (int) $data['quantity'];

        $batch = DB::transaction(function () use ($product, $data, $quantity, $request) {
            // Open the lot empty, then let InventoryService add the units. Writing
            // the quantity on the insert as well would count the delivery twice.
            $data['quantity'] = 0;
            $batch = $product->batches()->create($data);

            if ($quantity > 0) {
                app(InventoryService::class)->increase(
                    $product,
                    $quantity,
                    InventoryMovementType::StockIn,
                    $this->reason($batch, 'New lot'),
                    $batch,
                    $request->user(),
                    $batch,
                );
            }

            return $batch;
        });

        AuditLogger::record(
            AuditLogger::STOCK_ADJUSTED,
            sprintf('Recorded lot %s on "%s": %d units%s.',
                $batch->batch_no ?: '(unlabelled)',
                $product->name,
                (int) $batch->quantity,
                $batch->expiry_date ? ', expires '.$batch->expiry_date->toFormattedDateString() : ', no expiry'
            ),
            $product,
            null,
            ['batch_no' => $batch->batch_no, 'quantity' => $batch->quantity, 'expiry_date' => $batch->expiry_date?->toDateString()],
        );

        return redirect()
            ->route('admin.batches.index', $product)
            ->with('success', sprintf('Lot recorded for "%s".', $product->name));
    }

    public function update(BatchRequest $request, ProductBatch $batch): RedirectResponse
    {
        $product = $batch->product;
        $data = $request->validated();

        DB::transaction(function () use ($batch, $product, $data) {
            $previous = (int) $batch->quantity;
            $target = (int) $data['quantity'];

            // Persist the descriptive fields only. Writing the quantity here
            // would skip the movement log, and reading the delta afterwards would
            // always be zero because the model already carries the new value.
            $batch->fill(Arr::except($data, 'quantity'))->save();

            // Raising a lot's count is a stock-in, so it goes through the service
            // and keeps a before/after trail. Lowering it is refused by the form
            // request: those units have to be removed with a stock adjustment.
            if ($target > $previous) {
                app(InventoryService::class)->increase(
                    $product,
                    $target - $previous,
                    InventoryMovementType::StockIn,
                    'Lot quantity corrected',
                    $batch,
                    null,
                    $batch,
                );
            }
        });

        return redirect()
            ->route('admin.batches.index', $product)
            ->with('success', sprintf('Lot updated for "%s".', $product->name));
    }

    public function destroy(ProductBatch $batch): RedirectResponse
    {
        $product = $batch->product;

        if ((int) $batch->quantity > 0) {
            return back()->with('error', sprintf(
                'Cannot remove lot %s while it still holds %d units. Remove them with a stock adjustment first.',
                $batch->batch_no ?: '(unlabelled)',
                (int) $batch->quantity,
            ));
        }

        $label = $batch->batch_no ?: '(unlabelled)';

        AuditLogger::record(
            AuditLogger::STOCK_ADJUSTED,
            sprintf('Removed lot %s from "%s".', $label, $product->name),
            $product,
            ['batch_no' => $batch->batch_no, 'expiry_date' => $batch->expiry_date?->toDateString()],
            null,
        );

        $batch->delete();

        return redirect()
            ->route('admin.batches.index', $product)
            ->with('success', sprintf('Lot %s removed from "%s".', $label, $product->name));
    }

    private function reason(ProductBatch $batch, string $prefix): string
    {
        return sprintf(
            '%s %s',
            $prefix,
            $batch->expiry_date ? '(expires '.$batch->expiry_date->toFormattedDateString().')' : '(no expiry)'
        );
    }
}
