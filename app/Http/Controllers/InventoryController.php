<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Stock levels - readable by staff (read-only) and admins.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'status' => ['nullable', Rule::in(['low', 'out', 'ok', 'expiring', 'expired'])],
        ]);

        $query = Product::query()->with(['category', 'batches']);

        $query->search($validated['q'] ?? null);

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        match ($validated['status'] ?? null) {
            'low' => $query->lowStock(),
            'out' => $query->where('stock', '<=', 0),
            'ok' => $query->whereColumn('stock', '>', 'low_stock_threshold'),
            'expiring' => $query->expiringStock(),
            'expired' => $query->expiredStock(),
            default => null,
        };

        $query->orderBy('stock')->orderBy('name');

        $canAdjust = $request->user()->isAdmin();

        return view('inventory.index', [
            'products' => $query->paginate(15)->withQueryString(),
            'categories' => Category::orderBy('name')->get(),
            'filters' => $validated,
            'canAdjust' => $canAdjust,
            'lowStockCount' => Product::lowStock()->count(),
            'outOfStockCount' => Product::where('stock', '<=', 0)->count(),
            'expiringCount' => Product::expiringStock()->count(),
            'expiredCount' => Product::expiredStock()->count(),
            'warningDays' => ProductBatch::EXPIRY_WARNING_DAYS,
            'stockValue' => (float) Product::query()->selectRaw('COALESCE(SUM(stock * cost_price), 0) as v')->value('v'),
        ]);
    }

    /**
     * The stock-in / stock-out history log. Admin only.
     */
    public function movements(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(InventoryMovementType::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = InventoryMovement::query()->with(['product.category', 'user', 'batch']);

        if ($term = trim((string) ($validated['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('reason', 'like', $like)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like));
            });
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        return view('inventory.movements', [
            'movements' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => $validated,
            'types' => collect(InventoryMovementType::cases())
                ->mapWithKeys(fn (InventoryMovementType $type) => [$type->value => $type->label()])
                ->all(),
        ]);
    }

    public function create(Request $request, Product $product): View
    {
        $batches = $product->batches()
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        return view('inventory.adjust', [
            'product' => $product,
            'types' => [
                InventoryMovementType::StockIn->value => InventoryMovementType::StockIn->label(),
                InventoryMovementType::StockOut->value => InventoryMovementType::StockOut->label(),
                InventoryMovementType::Adjustment->value => InventoryMovementType::Adjustment->label(),
            ],
            'batches' => $batches,
            'expiringCount' => $batches->filter(fn (ProductBatch $b) => $b->status() === 'expiring')->count(),
            'expiredCount' => $batches->filter(fn (ProductBatch $b) => $b->status() === 'expired')->count(),
        ]);
    }

    public function store(StockAdjustmentRequest $request, Product $product): RedirectResponse
    {
        $type = InventoryMovementType::from($request->validated('type'));
        $reason = $request->validated('reason');
        $user = $request->user();
        $batch = $this->resolveBatch($request, $product);

        $movements = DB::transaction(function () use ($product, $type, $reason, $request, $user, $batch) {
            return match ($type) {
                InventoryMovementType::StockIn => $this->inventory->increase(
                    $product,
                    (int) $request->validated('quantity'),
                    $type,
                    $reason,
                    null,
                    $user,
                    $batch,
                    $request->newBatch(),
                ),
                InventoryMovementType::StockOut => $this->inventory->decrease(
                    $product,
                    (int) $request->validated('quantity'),
                    $type,
                    $reason,
                    null,
                    $user,
                    $batch,
                ),
                default => $this->inventory->setStock(
                    $product,
                    (int) $request->validated('new_stock'),
                    $reason,
                    $user,
                    $batch,
                    $request->newBatch(),
                ),
            };
        });

        $product->refresh();

        // A movement can span several lots, so the summary is the first row's
        // opening figure and the last row's closing one.
        $first = $movements->first();
        $last = $movements->last();

        $lotNote = $movements->count() > 1
            ? sprintf(' across %d lots', $movements->count())
            : '';

        AuditLogger::record(
            AuditLogger::STOCK_ADJUSTED,
            sprintf(
                '%s on "%s": %+d units (%d -> %d)%s. Reason: %s',
                $type->label(),
                $product->name,
                (int) $last->quantity,
                (int) $first->before_stock,
                (int) $last->after_stock,
                $lotNote,
                $reason,
            ),
            $product,
            ['stock' => $first->before_stock],
            ['stock' => $last->after_stock],
        );

        return redirect()
            ->route('inventory.index')
            ->with('success', sprintf(
                'Stock updated for "%s" (%d -> %d).',
                $product->name,
                (int) $first->before_stock,
                (int) $last->after_stock,
            ));
    }

    /**
     * The lot the administrator picked, or null to let the service choose.
     */
    private function resolveBatch(StockAdjustmentRequest $request, Product $product): ?ProductBatch
    {
        $id = $request->validated('batch_id');

        if (blank($id)) {
            return null;
        }

        return $product->batches()->whereKey($id)->first();
    }
}
