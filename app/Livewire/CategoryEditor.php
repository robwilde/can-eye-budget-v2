<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Transactions\CategoryAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

final class CategoryEditor extends Component
{
    public string $search = '';

    public ?int $selectedCategoryId = null;

    public bool $showHidden = false;

    public string $editingName = '';

    public bool $showCreateForm = false;

    public string $newCategoryName = '';

    public ?int $newParentId = null;

    public bool $showDeleteConfirm = false;

    public ?int $deletingCategoryId = null;

    public string $deletingCategoryName = '';

    public int $deletingTransactionCount = 0;

    public function selectCategory(int $id): void
    {
        if ($this->selectedCategoryId === $id) {
            $this->selectedCategoryId = null;
            $this->editingName = '';
            $this->showDeleteConfirm = false;

            return;
        }

        $category = Category::find($id);

        if (! $category) {
            return;
        }

        $this->selectedCategoryId = $category->id;
        $this->editingName = $category->name;
        $this->showDeleteConfirm = false;
    }

    public function saveRename(): void
    {
        if (! $this->selectedCategoryId) {
            return;
        }

        $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
        ]);

        Category::find($this->selectedCategoryId)?->update([
            'name' => $this->editingName,
        ]);
    }

    public function toggleHidden(int $id): void
    {
        $category = Category::find($id);

        if (! $category) {
            return;
        }

        $category->update(['is_hidden' => ! $category->is_hidden]);
    }

    public function openCreateForm(?int $parentId = null): void
    {
        $this->showCreateForm = true;
        $this->newCategoryName = '';
        $this->newParentId = $parentId;
    }

    public function createCategory(): void
    {
        $this->validate([
            'newCategoryName' => ['required', 'string', 'max:255'],
            'newParentId' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        Category::create([
            'name' => $this->newCategoryName,
            'parent_id' => $this->newParentId,
        ]);

        $this->showCreateForm = false;
        $this->newCategoryName = '';
        $this->newParentId = null;
    }

    public function confirmDelete(int $id): void
    {
        $category = Category::find($id);

        if (! $category) {
            return;
        }

        $this->deletingCategoryId = $category->id;
        $this->deletingCategoryName = $category->fullPath();
        $this->deletingTransactionCount = (int) CategoryAttribution::query(auth()->id())
            ->where('category_id', $category->id)
            ->distinct()
            ->count('transaction_id');
        $this->showDeleteConfirm = true;
    }

    public function deleteCategory(): void
    {
        if (! $this->deletingCategoryId) {
            return;
        }

        Category::find($this->deletingCategoryId)?->delete();

        if ($this->selectedCategoryId === $this->deletingCategoryId) {
            $this->selectedCategoryId = null;
            $this->editingName = '';
        }

        $this->showDeleteConfirm = false;
        $this->deletingCategoryId = null;
        $this->deletingCategoryName = '';
        $this->deletingTransactionCount = 0;
    }

    public function render(): View
    {
        $search = $this->search;
        $isSearching = $search !== '';

        $counts = CategoryAttribution::query(auth()->id())
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->select('category_id', DB::raw('COUNT(DISTINCT transaction_id) as aggregate'))
            ->pluck('aggregate', 'category_id');

        $categories = Category::query()
            ->with(['parent.parent'])
            ->when(! $this->showHidden, fn ($q) => $q->visible())
            ->when($isSearching, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('parent', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('parent.parent', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
            }))
            ->get()
            ->sortBy(fn (Category $category): string => Str::lower($category->fullPath()), SORT_NATURAL)
            ->values()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->fullPath(),
                'depth' => $category->depth(),
                'transactions_count' => (int) ($counts[$category->id] ?? 0),
                'is_hidden' => $category->is_hidden,
                'parent_id' => $category->parent_id,
            ]);

        $transactions = $this->selectedCategoryId
            ? Transaction::query()
                ->where('user_id', auth()->id())
                ->current()
                ->where(fn ($q) => $q->where('category_id', $this->selectedCategoryId)
                    ->orWhereHas('splits', fn ($s) => $s->where('category_id', $this->selectedCategoryId)))
                ->with('account:id,name')
                ->orderByDesc('post_date')
                ->limit(50)
                ->get()
            : collect();

        $parentOptions = Category::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.category-editor', [
            'categories' => $categories,
            'transactions' => $transactions,
            'parentOptions' => $parentOptions,
            'isSearching' => $isSearching,
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
