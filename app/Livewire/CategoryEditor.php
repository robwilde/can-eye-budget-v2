<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Exceptions\CategoriesLockedException;
use App\Exceptions\CategoryMergeRefusedException;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryCreator;
use App\Services\CategoryMerger;
use App\Services\Reports\CategoryGroupMap;
use App\Support\Categories\ProtectedCategories;
use App\Support\Transactions\CategoryAttribution;
use Closure;
use Flux\Flux;
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

    public ?int $deletingReplacementId = null;

    public string $editingColor = '';

    public string $editingIcon = '';

    public bool $showMergeForm = false;

    /**
     * @var list<int|string>
     */
    public array $mergeSourceIds = [];

    public ?int $mergeTargetId = null;

    public function selectCategory(int $id): void
    {
        $this->resetErrorBag();

        if ($this->selectedCategoryId === $id) {
            $this->selectedCategoryId = null;
            $this->editingName = '';
            $this->editingParentId = null;
            $this->editingGroupId = null;
            $this->showDeleteConfirm = false;

            return;
        }

        $category = Category::query()->where('user_id', $this->authenticatedUser()->id)->find($id);

        if (! $category) {
            return;
        }

        $this->selectedCategoryId = $category->id;
        $this->editingName = $category->name;
        $this->editingParentId = $category->parent_id;
        $this->editingGroupId = $category->group_id;
        $this->editingColor = $category->color ?? '';
        $this->editingIcon = $category->icon ?? '';
        $this->showDeleteConfirm = false;
    }

    public function saveRename(): void
    {
        if (! $this->selectedCategoryId) {
            return;
        }

        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'editingName' => ['required', 'string', 'max:255', $this->reservedNameRule()],
            'editingParentId' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'editingColor' => ['nullable', 'regex:'.CategoryGroup::COLOR_PATTERN],
            'editingIcon' => ['nullable', 'string', Rule::in($this->iconOptions($userId))],
        ]);

        $category = Category::allWithLinkedParents($userId)->firstWhere('id', $this->selectedCategoryId);

        if ($category === null) {
            return;
        }

        if ($this->wouldCreateCycle($userId, $this->selectedCategoryId, $this->editingParentId)) {
            $this->addError('editingParentId', __('A category cannot be moved under itself or one of its subcategories.'));

            return;
        }

        if (ProtectedCategories::isProtected($category) && ($this->editingName !== $category->name || $this->editingParentId !== $category->parent_id)) {
            $this->addError('editingName', __('This category is found by name elsewhere in the app, so it cannot be renamed or moved.'));

            return;
        }

        if (! ProtectedCategories::isProtected($category) && ProtectedCategories::isProtectedPath($this->resultingPath($userId, $this->editingParentId, $this->editingName))) {
            $this->addError('editingName', __('That name is used by a category the app finds by name, so it cannot be used here.'));

            return;
        }

        if ($this->editingParentId !== $category->parent_id && $this->exceedsMaxLevels($userId, $category->id, $this->editingParentId)) {
            $this->addError('editingParentId', __('Categories can only be nested :levels levels deep.', ['levels' => Category::MAX_LEVELS]));

            return;
        }

        $parent = $this->editingParentId === null ? null : Category::query()->where('user_id', $userId)->find($this->editingParentId);
        $color = $parent !== null ? $parent->color : ($this->editingColor === '' ? null : $this->editingColor);

        DB::transaction(function () use ($category, $userId, $color): void {
            $category->update([
                'name' => $this->editingName,
                'parent_id' => $this->editingParentId,
                'color' => $color,
                'icon' => $this->editingIcon === '' ? null : $this->editingIcon,
            ]);

            if ($category->wasChanged('color')) {
                $this->recolourDescendants($userId, $category->id, $color);
            }
        });

        $this->editingColor = $color ?? '';
    }

    public function saveCategoryGroup(): void
    {
        if (! $this->selectedCategoryId) {
            return;
        }

        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'editingGroupId' => ['nullable', 'integer', Rule::exists('category_groups', 'id')->where('user_id', $userId)],
        ]);

        Category::query()
            ->where('user_id', $userId)
            ->whereKey($this->selectedCategoryId)
            ->first()
            ?->update(['group_id' => $this->editingGroupId]);
    }

    public function openGroupForm(?int $groupId = null): void
    {
        $group = $groupId === null ? null : CategoryGroup::query()->where('user_id', $this->authenticatedUser()->id)->find($groupId);

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
        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'groupName' => ['required', 'string', 'max:255', Rule::unique('category_groups', 'name')->where('user_id', $userId)->ignore($this->editingGroupRecordId)],
            'groupColor' => ['required', 'regex:'.CategoryGroup::COLOR_PATTERN],
            'groupIcon' => ['required', Rule::in(CategoryGroup::ICONS)],
        ]);

        $attributes = ['name' => $this->groupName, 'color' => $this->groupColor, 'icon' => $this->groupIcon];

        if ($this->editingGroupRecordId === null) {
            CategoryGroup::create($attributes + [
                'user_id' => $userId,
                'sort_order' => (int) CategoryGroup::query()->where('user_id', $userId)->max('sort_order') + 1,
            ]);
        } else {
            CategoryGroup::query()->where('user_id', $userId)->findOrFail($this->editingGroupRecordId)->update($attributes);
        }

        $this->closeGroupForm();
    }

    public function toggleHidden(int $id): void
    {
        $category = Category::query()->where('user_id', $this->authenticatedUser()->id)->find($id);

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

    public function createCategory(CategoryCreator $creator): void
    {
        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'newCategoryName' => ['required', 'string', 'max:255', $this->reservedNameRule()],
            'newParentId' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);

        if ($this->exceedsMaxLevels($userId, null, $this->newParentId)) {
            $this->addError('newParentId', __('Categories can only be nested :levels levels deep.', ['levels' => Category::MAX_LEVELS]));

            return;
        }

        if (ProtectedCategories::isProtectedPath($this->resultingPath($userId, $this->newParentId, $this->newCategoryName))) {
            $this->addError('newCategoryName', __('That name is used by a category the app finds by name, so it cannot be created.'));

            return;
        }

        try {
            $creator->create($this->authenticatedUser(), $this->newCategoryName, $this->newParentId);
        } catch (CategoriesLockedException $exception) {
            $this->addError('newCategoryName', $exception->getMessage());

            return;
        }

        $this->showCreateForm = false;
        $this->newCategoryName = '';
        $this->newParentId = null;
    }

    public function confirmDelete(int $id): void
    {
        $this->resetErrorBag();

        $userId = $this->authenticatedUser()->id;
        $category = Category::allWithLinkedParents($userId)->firstWhere('id', $id);

        if (! $category) {
            return;
        }

        if (ProtectedCategories::isProtected($category)) {
            $this->addError('deletingCategoryId', __('This category is found by name elsewhere in the app, so it cannot be deleted.'));

            return;
        }

        $this->deletingCategoryId = $category->id;
        $this->deletingCategoryName = $category->fullPath();
        $this->deletingTransactionCount = (int) CategoryAttribution::query($userId)
            ->where('category_id', $category->id)
            ->distinct()
            ->count('transaction_id');
        $this->deletingReplacementId = null;
        $this->showDeleteConfirm = true;
    }

    public function deleteCategory(CategoryMerger $merger): void
    {
        $this->resetErrorBag();

        if (! $this->deletingCategoryId) {
            return;
        }

        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'deletingReplacementId' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);

        $category = Category::allWithLinkedParents($userId)->firstWhere('id', $this->deletingCategoryId);

        if ($category !== null && ProtectedCategories::isProtected($category)) {
            $this->addError('deletingCategoryId', __('This category is found by name elsewhere in the app, so it cannot be deleted.'));

            return;
        }

        if ($category !== null) {
            try {
                $merger->deleteWithReplacement($this->authenticatedUser(), $category->id, $this->deletingReplacementId);
            } catch (CategoryMergeRefusedException $exception) {
                $this->addError('deletingCategoryId', $exception->getMessage());

                return;
            }
        }

        if ($this->selectedCategoryId === $this->deletingCategoryId) {
            $this->selectedCategoryId = null;
            $this->editingName = '';
            $this->editingParentId = null;
        }

        $this->showDeleteConfirm = false;
        $this->deletingCategoryId = null;
        $this->deletingCategoryName = '';
        $this->deletingTransactionCount = 0;
        $this->deletingReplacementId = null;
    }

    public function openMergeForm(): void
    {
        $this->resetErrorBag();
        $this->showMergeForm = true;
        $this->mergeSourceIds = $this->selectedCategoryId === null ? [] : [$this->selectedCategoryId];
        $this->mergeTargetId = null;
    }

    public function closeMergeForm(): void
    {
        $this->resetErrorBag();
        $this->resetMergeForm();
    }

    public function mergeCategories(CategoryMerger $merger): void
    {
        $this->resetErrorBag();

        $userId = $this->authenticatedUser()->id;

        $this->validate([
            'mergeSourceIds' => ['required', 'array', 'min:1'],
            'mergeSourceIds.*' => ['integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'mergeTargetId' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);

        $sourceIds = array_map(intval(...), $this->mergeSourceIds);

        try {
            $merger->merge($this->authenticatedUser(), $sourceIds, (int) $this->mergeTargetId);
        } catch (CategoryMergeRefusedException $exception) {
            $this->addError('mergeSourceIds', $exception->getMessage());

            return;
        }

        if ($this->selectedCategoryId !== null && in_array($this->selectedCategoryId, $sourceIds, true)) {
            $this->selectedCategoryId = null;
            $this->editingName = '';
            $this->editingParentId = null;
        }

        $this->resetMergeForm();
        Flux::toast(text: __('Categories merged.'), variant: 'success');
    }

    public function toggleCategoriesLocked(): void
    {
        $user = $this->authenticatedUser();

        $user->updatePreferences(['categoriesLocked' => ! $user->preferences->categoriesLocked]);
    }

    public function render(): View
    {
        $search = $this->search;
        $isSearching = $search !== '';
        $searchLower = Str::lower($search);

        $userId = $this->authenticatedUser()->id;

        $counts = CategoryAttribution::query($userId)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->select('category_id', DB::raw('COUNT(DISTINCT transaction_id) as aggregate'))
            ->pluck('aggregate', 'category_id');

        $groupMap = CategoryGroupMap::load($userId);
        $groups = CategoryGroup::query()->where('user_id', $userId)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');

        $categories = Category::allWithLinkedParents($userId)
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
                ->where('user_id', $userId)
                ->current()
                ->where(fn ($q) => $q->where('category_id', $this->selectedCategoryId)
                    ->orWhereHas('splits', fn ($s) => $s->where('category_id', $this->selectedCategoryId)))
                ->with('account:id,name')
                ->orderByDesc('post_date')
                ->limit(50)
                ->get()
            : collect();

        $parentOptions = Category::visibleSortedByFullPath($userId);

        $mergeSourceIds = array_map(intval(...), array_filter($this->mergeSourceIds, is_numeric(...)));
        $mergeTransactionCount = $this->showMergeForm && $mergeSourceIds !== []
            ? (int) CategoryAttribution::query($userId)->whereIn('category_id', $mergeSourceIds)->distinct()->count('transaction_id')
            : 0;

        return view('livewire.category-editor', [
            'categories' => $categories,
            'transactions' => $transactions,
            'parentOptions' => $parentOptions,
            'groups' => $groups,
            'isSearching' => $isSearching,
            'formatMoney' => MoneyCast::format(...),
            'categoriesLocked' => $this->authenticatedUser()->preferences->categoriesLocked,
            'mergeTransactionCount' => $mergeTransactionCount,
            'icons' => $this->iconOptions($userId),
        ]);
    }

    private function resultingPath(int $userId, ?int $parentId, string $name): string
    {
        $parent = $parentId === null ? null : Category::allWithLinkedParents($userId)->firstWhere('id', $parentId);

        return $parent === null ? $name : $parent->fullPath().' / '.$name;
    }

    private function exceedsMaxLevels(int $userId, ?int $categoryId, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        $all = Category::allWithLinkedParents($userId);
        $parent = $all->firstWhere('id', $parentId);

        if ($parent === null) {
            return false;
        }

        $height = 0;
        $level = $categoryId === null ? [] : [$categoryId];
        $visited = [];

        while ($level !== []) {
            $visited = [...$visited, ...$level];
            $level = $all->whereIn('parent_id', $level)->pluck('id')->diff($visited)->values()->all();
            $height += $level === [] ? 0 : 1;
        }

        return $parent->depth() + 1 + $height >= Category::MAX_LEVELS;
    }

    private function reservedNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && mb_strtolower(mb_trim($value)) === Category::RESERVED_NAME) {
                $fail(__('That name is reserved.'));
            }
        };
    }

    /**
     * @return list<string> The group icon set plus every icon already on one of the user's categories.
     */
    private function iconOptions(int $userId): array
    {
        $used = Category::query()->where('user_id', $userId)->whereNotNull('icon')->distinct()->pluck('icon')->all();

        return collect([...CategoryGroup::ICONS, ...$used])->unique()->sort()->values()->all();
    }

    private function resetMergeForm(): void
    {
        $this->showMergeForm = false;
        $this->mergeSourceIds = [];
        $this->mergeTargetId = null;
    }

    private function recolourDescendants(int $userId, int $categoryId, ?string $color): void
    {
        $level = [$categoryId];

        while ($level !== []) {
            Category::query()->where('user_id', $userId)->whereIn('parent_id', $level)->update(['color' => $color]);
            $level = Category::query()->where('user_id', $userId)->whereIn('parent_id', $level)->pluck('id')->all();
        }
    }

    private function wouldCreateCycle(int $userId, int $categoryId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $categoryId) {
            return true;
        }

        $parents = Category::query()->where('user_id', $userId)->pluck('parent_id', 'id');
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

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
