<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.categories.index', [
            'categories' => Category::query()
                ->withCount('products')
                ->orderBy('name')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.create', [
            'category' => new Category,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $category = Category::create([
            ...$request->safe()->except('is_active'),
            // A new category is sellable unless it is explicitly switched off.
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->record(
            action: AuditLogger::CATEGORY_CREATED,
            subject: $category,
            userId: $request->user()->id,
            description: "Created category [{$category->name}]",
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', "Category [{$category->name}] created.");
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', [
            'category' => $category,
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->fill([
            ...$request->safe()->except('is_active'),
            'is_active' => $request->boolean('is_active'),
        ])->save();

        $this->audit->record(
            action: AuditLogger::CATEGORY_UPDATED,
            subject: $category,
            userId: $request->user()->id,
            description: "Updated category [{$category->name}]",
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', "Category [{$category->name}] updated.");
    }

    /**
     * Deleting a category detaches its products rather than deleting them.
     */
    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $productCount = $category->products()->count();
        $name = $category->name;

        // Detaching the products and removing the category are one operation:
        // if the delete fails, the products must keep their category rather
        // than being left orphaned by a half-applied change.
        DB::transaction(function () use ($category, $productCount): void {
            if ($productCount > 0) {
                $category->products()->update(['category_id' => null]);
            }

            $category->delete();
        });

        $this->audit->record(
            action: AuditLogger::CATEGORY_DELETED,
            userId: $request->user()->id,
            description: $productCount > 0
                ? "Deleted category [{$name}] and uncategorised {$productCount} product(s)"
                : "Deleted category [{$name}]",
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', "Category [{$name}] deleted.".($productCount > 0 ? " {$productCount} product(s) are now uncategorised." : ''));
    }
}
