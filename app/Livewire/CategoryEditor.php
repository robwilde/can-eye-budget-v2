<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\Category;
use App\Models\Transaction;
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
        $this->deletingTransactionCount = $category->transactions()
            ->where('user_id', auth()->id())
            ->count();
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
        $categories = Category::query()
            ->with(['parent.parent'])
            ->withCount(['transactions' => fn ($q) => $q->where('user_id', auth()->id())])
            ->when(! $this->showHidden, fn ($q) => $q->visible())
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->fullPath(),
                'transactions_count' => $category->transactions_count,
                'is_hidden' => $category->is_hidden,
                'parent_id' => $category->parent_id,
            ]);

        if ($this->search !== '') {
            $categories = $categories->filter(
                fn (array $item) => str_contains(
                    mb_strtolower($item['full_path']),
                    mb_strtolower($this->search),
                ),
            );
        }

        $categories = $categories->sortByDesc('transactions_count')->values();

        $transactions = $this->selectedCategoryId
            ? Transaction::query()
                ->where('category_id', $this->selectedCategoryId)
                ->where('user_id', auth()->id())
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
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
