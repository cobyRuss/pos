<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('categories.index', [
            'categories' => Category::query()
                ->withCount('products')
                ->withSum('products as products_stock', 'stock')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('categories.create', [
            'category' => new Category(['is_active' => true]),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->validated());

        AuditLogger::record(
            AuditLogger::CATEGORY_CREATED,
            sprintf('Created category "%s".', $category->name),
            $category,
            null,
            $category->only(['name', 'is_active']),
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', sprintf('Category "%s" created.', $category->name));
    }

    public function edit(Category $category): View
    {
        return view('categories.edit', [
            'category' => $category->loadCount('products'),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $before = $category->only(['name', 'description', 'is_active']);

        $category->update($request->validated());

        $diff = AuditLogger::diff($before, $category->only(['name', 'description', 'is_active']));

        AuditLogger::record(
            AuditLogger::CATEGORY_UPDATED,
            sprintf('Updated category "%s".', $category->name),
            $category,
            $diff['old'],
            $diff['new'],
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', sprintf('Category "%s" updated.', $category->name));
    }

    public function destroy(Category $category): RedirectResponse
    {
        $name = $category->name;
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            return back()->with('error', sprintf(
                'Cannot delete "%s" - it still has %d product(s). Move or delete them first.',
                $name,
                $productCount,
            ));
        }

        AuditLogger::record(
            AuditLogger::CATEGORY_DELETED,
            sprintf('Deleted category "%s".', $name),
            $category,
            $category->only(['name', 'description', 'is_active']),
        );

        $category->delete();

        return redirect()
            ->route('admin.categories.index')
            ->with('success', sprintf('Category "%s" deleted.', $name));
    }
}
