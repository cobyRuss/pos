<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustStockRequest;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected AuditLogger $audit,
    ) {}

    /**
     * Stock balances. Staff reach this read-only; admins also get the adjust
     * controls.
     */
    public function index(Request $request): View
    {
        $canAdjust = $request->user()->can(Permission::InventoryAdjust->value);

        $query = Product::query()
            ->with('category')
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($request->input('status') === 'low_stock', fn ($q) => $q->lowStock())
            ->when($request->input('status') === 'out_of_stock', fn ($q) => $q->where('stock', '<=', 0))
            ->orderBy('name');

        $products = (clone $query)->paginate(20)->withQueryString();

        return view('admin.inventory.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'filters' => $request->only(['search', 'category', 'status']),
            'canAdjust' => $canAdjust,
            'totals' => [
                'products' => Product::count(),
                'units' => (int) Product::sum('stock'),
                'stock_value' => round((float) Product::query()->sum('stock * price'), 2),
                'low_stock' => Product::lowStock()->count(),
                'out_of_stock' => Product::where('stock', '<=', 0)->count(),
            ],
        ]);
    }

    public function create(Request $request, Product $product): View
    {
        $this->authorizeAdjustment($request);

        return view('admin.inventory.adjust', [
            'product' => $product,
        ]);
    }

    public function store(AdjustStockRequest $request, Product $product): RedirectResponse
    {
        $action = $request->validated('action');
        $quantity = (int) $request->validated('quantity');
        $notes = $request->validated('notes');
        $user = $request->user();

        try {
            $log = match ($action) {
                'restock' => $this->inventory->restock($product, $quantity, $user, $notes),
                'write_off' => $this->inventory->writeOff($product, $quantity, $user, $notes),
                default => $this->inventory->setLevel($product, $quantity, $user, $notes),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // A count that already matches the recorded level changes nothing, so
        // there is no movement and nothing to audit.
        if ($log === null) {
            return redirect()
                ->route('inventory.index')
                ->with('success', "Stock for [{$product->name}] is already {$product->fresh()->stock}; nothing to change.");
        }

        $this->audit->record(
            action: AuditLogger::STOCK_ADJUSTED,
            subject: $product,
            userId: $user->id,
            description: sprintf(
                '%s [%s] for %s by %+d (new level %d)%s',
                match ($action) {
                    'restock' => 'Restocked',
                    'write_off' => 'Wrote off',
                    default => 'Counted and set',
                },
                $action,
                $product->name,
                $log->quantity_change,
                $product->fresh()->stock,
                $notes ? ": {$notes}" : '',
            ),
        );

        return redirect()
            ->route('inventory.index')
            ->with('success', "Stock for [{$product->name}] is now {$product->fresh()->stock}.");
    }

    /**
     * The immutable movement history.
     */
    public function logs(Request $request): View
    {
        $logs = InventoryLog::query()
            ->with(['product', 'user'])
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', $request->integer('product')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.inventory.logs', [
            'logs' => $logs,
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['product', 'type', 'user', 'from', 'to']),
        ]);
    }

    protected function authorizeAdjustment(Request $request): void
    {
        abort_unless($request->user()->can(Permission::InventoryAdjust->value), 403);
    }
}
