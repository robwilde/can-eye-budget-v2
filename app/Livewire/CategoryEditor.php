<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Services\Reports\CategoryGroupMap;
use App\Support\Transactions\CategoryAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

final class CategoryEditor extends Component
{
    public string $search = '';

    public ?int $selectedCategoryId = null;

    public bool $showHidden = false;

    public string $editingName = '';

    public ?int $editingParentId = null;

    public ?int $editingGroupId = null;

    public bool $showGroupForm = false;

    public ?int $editingGroupRecordId = null;

    public string $groupName = '';

    public string $groupColor = '#6471eb';

    public string $groupIcon = 'sparkles';

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
            $this->editingParentId = null;
            $this->editingGroupId = null;
            $this->showDeleteConfirm = false;

            return;
        }

        $category = Category::find($id);

        if (! $category) {
            return;
        }

        $this->selectedCategoryId = $category->id;
        $this->editingName = $category->name;
        $this->editingParentId = $category->parent_id;
        $this->editingGroupId = $category->group_id;
        $this->showDeleteConfirm = false;
    }

    public function saveRename(): void
    {
        if (! $this->selectedCategoryId) {
            return;
        }

        $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
            'editingParentId' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        if ($this->wouldCreateCycle($this->selectedCategoryId, $this->editingParentId)) {
            $this->addError('editingParentId', __('A category cannot be moved under itself or one of its subcategories.'));

            return;
        }

        Category::find($this->selectedCategoryId)?->update([
            'name' => $this->editingName,
            'parent_id' => $this->editingParentId,
        ]);
    }

    public function saveCategoryGroup(): void
    {
        if (! $this->selectedCategoryId) {
            return;
        }

        $this->validate([
            'editingGroupId' => ['nullable', 'integer', 'exists:category_groups,id'],
        ]);

        Category::find($this->selectedCategoryId)?->update(['group_id' => $this->editingGroupId]);
    }

    public function openGroupForm(?int $groupId = null): void
    {
        $group = $groupId === null ? null : CategoryGroup::find($groupId);

        $this->showGroupForm = true;
        $this->editingGroupRecordId = $group?->id;
        $this->groupName = $group->name ?? '';
        $this->groupColor = $group->color ?? '#6471eb';
        $this->groupIcon = $group->icon ?? 'sparkles';
        $this->resetErrorBag();
    }

    public function closeGroupForm(): void
    {
        $this->showGroupForm = false;
        $this->editingGroupRecordId = null;
        $this->resetErrorBag();
    }

    public function saveGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:255', Rule::unique('category_groups', 'name')->ignore($this->editingGroupRecordId)],
            'groupColor' => ['required', 'regex:'.CategoryGroup::COLOR_PATTERN],
            'groupIcon' => ['required', Rule::in(CategoryGroup::ICONS)],
        ]);

        $attributes = ['name' => $this->groupName, 'color' => $this->groupColor, 'icon' => $this->groupIcon];

        if ($this->editingGroupRecordId === null) {
            CategoryGroup::create($attributes + ['sort_order' => (int) CategoryGroup::query()->max('sort_order') + 1]);
        } else {
            CategoryGroup::findOrFail($this->editingGroupRecordId)->update($attributes);
        }

        $this->closeGroupForm();
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
            $this->editingParentId = null;
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
        $searchLower = Str::lower($search);

        $counts = CategoryAttribution::query(auth()->id())
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->select('category_id', DB::raw('COUNT(DISTINCT transaction_id) as aggregate'))
            ->pluck('aggregate', 'category_id');

        $groupMap = CategoryGroupMap::load();
        $groups = CategoryGroup::query()->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');

        $categories = Category::allWithLinkedParents()
            ->when(! $this->showHidden, fn ($c) => $c->reject(fn (Category $category): bool => $category->is_hidden))
            ->when($isSearching, fn ($c) => $c->filter(
                fn (Category $category): bool => str_contains(Str::lower($category->fullPath()), $searchLower)
            ))
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
                'group_id' => $category->group_id,
                'group' => ($groupId = $groupMap->groupId($category->id)) === null ? null : $groups->get($groupId),
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

        $parentOptions = Category::visibleSortedByFullPath();

        return view('livewire.category-editor', [
            'categories' => $categories,
            'transactions' => $transactions,
            'parentOptions' => $parentOptions,
            'groups' => $groups,
            'isSearching' => $isSearching,
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    private function wouldCreateCycle(int $categoryId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $categoryId) {
            return true;
        }

        $parents = Category::query()->pluck('parent_id', 'id');
        $cursor = $newParentId;
        $visited = [];

        while ($cursor !== null) {
            if ($cursor === $categoryId || isset($visited[$cursor])) {
                return true;
            }

            $visited[$cursor] = true;
            $cursor = $parents[$cursor] !== null ? (int) $parents[$cursor] : null;
        }

        return false;
    }
}
