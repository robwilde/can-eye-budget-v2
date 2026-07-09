# Inline categories on the Accounts page — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use executing-plans (or laravel:executing-plans) to implement this plan task-by-task.

**Goal:** Replace the `/accounts` "Manage Categories" modal with an inline, mobile-first grouped accordion that lists every category alphabetically (grouped by top-level category, sub-categories ordered), shows full names untruncated, and keeps all management (rename / hide / delete / create / recent transactions).

**Architecture:** Approach A from the design doc (`docs/plans/2026-07-09-accounts-inline-categories-design.md`) — keep the `CategoryEditor` Livewire component and its test seam; change only `render()` (sort + `depth` + `isSearching`) and rewrite its Blade view from a `flux:modal` into an inline `<x-cib.card>` accordion; delete the trigger card in `account-manager.blade.php`. `accounts.blade.php` is unchanged (still renders `<livewire:category-editor />`, now inline below accounts).

**Tech Stack:** Laravel 12, Livewire 4, Flux UI Free v2, Tailwind v4, Pest, DDEV (DB host `db`, `RefreshDatabase`). Quality gates: Pint + PHPStan via GrumPHP. Money is integer + `MoneyCast`.

**Conventions:** Issue-first. Branch name includes the issue number. Commits `type(#issue): subject` with a body ("why" + verification) and footer `Refs #<issue>` / `Verified: <status>`. PR targets `develop`, squash-merge, Copilot reviewer + labels. Run Pint/PHPStan before tests. PHPStan excludes `tests/`.

**Runner:** DDEV. Tests: `ddev artisan test`. Pint: `ddev exec ./vendor/bin/pint`. PHPStan: `ddev exec ./vendor/bin/phpstan analyse`. Assets: `ddev exec npm run build`.

---

## Task 0: Scaffolding

**Step 1:** Create the GitHub issue (title e.g. "Inline categories on /accounts (remove Manage Categories modal)"; labels: `frontend`, `enhancement`). Note the issue number `<N>`.

**Step 2:** Create the branch off `develop`:
```bash
git switch develop && git pull
git switch -c feat/<N>-inline-categories
```

**Step 3:** Confirm DDEV is running:
```bash
ddev start
ddev artisan test --filter="CategoryEditor" 
```
Expected: existing CategoryEditor tests PASS (baseline green before changes).

---

## Task 1: `Category::depth()`

**Files:**
- Modify: `app/Models/Category.php` (add method after `fullPath()`, ~line 108)
- Test: `tests/Feature/Models/CategoryTest.php` (add after the `fullPath` tests, ~line 170)

**Step 1: Write the failing tests**

Add to `tests/Feature/Models/CategoryTest.php`:
```php
test('depth returns 0 for a top-level category', function () {
    $category = Category::factory()->create();

    expect($category->depth())->toBe(0);
});

test('depth returns 1 for a child and 2 for a grandchild', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    $grandchild = Category::factory()->create(['parent_id' => $child->id]);

    expect($child->depth())->toBe(1)
        ->and($grandchild->depth())->toBe(2);
});
```

**Step 2: Run to verify failure**

Run: `ddev artisan test --filter="depth returns"`
Expected: FAIL — `Call to undefined method App\Models\Category::depth()`.

**Step 3: Implement**

In `app/Models/Category.php`, after `fullPath()`:
```php
public function depth(): int
{
    $depth = 0;
    $ancestor = $this->parent;

    while ($ancestor) {
        $depth++;
        $ancestor = $ancestor->parent;
    }

    return $depth;
}
```

**Step 4: Run to verify pass**

Run: `ddev artisan test --filter="depth returns"`
Expected: PASS (2 tests).

**Step 5: Commit**
```bash
git add app/Models/Category.php tests/Feature/Models/CategoryTest.php
git commit -m "feat(#<N>): add Category::depth() for accordion indentation" \
  -m "Why: inline category accordion needs per-row nesting depth. Verified: ddev artisan test --filter='depth returns'." \
  -m "Refs #<N>"
```

---

## Task 2: `CategoryEditor::render()` — alphabetical DFS sort + depth + isSearching

**Files:**
- Modify: `app/Livewire/CategoryEditor.php` (add `use Illuminate\Support\Str;`; rewrite `render()` lines 136-182)
- Test: `tests/Feature/Livewire/CategoryEditorTest.php`

**Step 1: Replace the transaction-count sort test with an alphabetical-order test**

In `tests/Feature/Livewire/CategoryEditorTest.php`, DELETE the test `categories sorted by transaction count descending` (~lines 255-268) and ADD:
```php
test('categories are ordered alphabetically grouped by full path', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Zoo']);
    $apple = Category::factory()->create(['name' => 'Apple']);
    Category::factory()->create(['name' => 'Banana', 'parent_id' => $apple->id]);
    Category::factory()->create(['name' => 'Ant', 'parent_id' => $apple->id]);

    $component = Livewire::actingAs($user)->test(CategoryEditor::class);
    $paths = collect($component->viewData('categories'))->pluck('full_path')->all();

    expect($paths)->toBe(['Apple', 'Apple / Ant', 'Apple / Banana', 'Zoo']);
});

test('view data carries depth for each category', function () {
    $user = User::factory()->create();
    $office = Category::factory()->create(['name' => 'Office']);
    $training = Category::factory()->create(['name' => 'Training', 'parent_id' => $office->id]);
    Category::factory()->create(['name' => 'Course', 'parent_id' => $training->id]);

    $component = Livewire::actingAs($user)->test(CategoryEditor::class);
    $byName = collect($component->viewData('categories'))->keyBy('name');

    expect($byName['Office']['depth'])->toBe(0)
        ->and($byName['Training']['depth'])->toBe(1)
        ->and($byName['Course']['depth'])->toBe(2);
});

test('isSearching view flag reflects the search term', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->assertViewHas('isSearching', false)
        ->set('search', 'Off')
        ->assertViewHas('isSearching', true);
});
```

**Step 2: Run to verify failure**

Run: `ddev artisan test --filter="ordered alphabetically|carries depth|isSearching view flag"`
Expected: FAIL (order differs / no `depth` key / no `isSearching`).

**Step 3: Implement `render()`**

In `app/Livewire/CategoryEditor.php` add the import:
```php
use Illuminate\Support\Str;
```
Replace `render()` (lines 136-182) with:
```php
public function render(): View
{
    $search = $this->search;
    $isSearching = $search !== '';

    $categories = Category::query()
        ->with(['parent.parent'])
        ->withCount(['transactions' => fn ($q) => $q->where('user_id', auth()->id())->current()])
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
            'transactions_count' => $category->transactions_count,
            'is_hidden' => $category->is_hidden,
            'parent_id' => $category->parent_id,
        ]);

    $transactions = $this->selectedCategoryId
        ? Transaction::query()
            ->where('category_id', $this->selectedCategoryId)
            ->where('user_id', auth()->id())
            ->current()
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
```

**Step 4: Run to verify pass**

Run: `ddev artisan test --filter="ordered alphabetically|carries depth|isSearching view flag"`
Expected: PASS (3 tests). Existing search/showHidden data tests still pass.

**Step 5: Commit**
```bash
git add app/Livewire/CategoryEditor.php tests/Feature/Livewire/CategoryEditorTest.php
git commit -m "feat(#<N>): sort categories alphabetically by full path with depth" \
  -m "Why: inline accordion groups by top-level category, sub-categories ordered. Replaces transactions_count sort. Verified: targeted CategoryEditor tests. Refs #<N>"
```

---

## Task 3: Convert `selectCategory` to a toggle + rewrite the view as an inline accordion

**Files:**
- Modify: `app/Livewire/CategoryEditor.php` (`selectCategory`, lines 37-48)
- Rewrite: `resources/views/livewire/category-editor.blade.php`
- Test: `tests/Feature/Livewire/CategoryEditorTest.php`

**Step 1: Write/adjust tests**

Update the existing `displays full category path for nested categories` test (~lines 38-41) so its assertion matches browse mode (own segment, not the concatenated path):
```php
// browse mode: parent header + child segment both visible
->assertSee('Office')
->assertSee('Software');
```
ADD these tests:
```php
test('shows the full path when searching', function () {
    $user = User::factory()->create();
    $office = Category::factory()->create(['name' => 'Office']);
    Category::factory()->create(['name' => 'Software', 'parent_id' => $office->id]);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->set('search', 'Software')
        ->assertSee('Office / Software');
});

test('selecting an already-expanded category collapses it', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', $category->id)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', null);
});

test('the manage-categories modal is gone (rendered inline)', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->assertDontSeeHtml('name="category-editor"')
        ->assertSeeHtml('cib-card');
});
```

**Step 2: Run to verify failure**

Run: `ddev artisan test --filter="CategoryEditor"`
Expected: FAIL — collapse test fails (no toggle), modal-gone test fails (modal still present), and the updated full-path test may fail until the view changes.

**Step 3a: Toggle in `selectCategory`**

Replace `selectCategory` (lines 37-48) in `app/Livewire/CategoryEditor.php`:
```php
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
```

**Step 3b: Rewrite the view**

Replace the entire `resources/views/livewire/category-editor.blade.php` with:
```blade
@php use App\Enums\TransactionDirection; @endphp
<div>
    <x-cib.card>
        <div class="flex flex-col space-y-4">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Categories') }}</flux:heading>
                @unless($showCreateForm)
                    <button type="button" wire:click="openCreateForm" class="cib-yellow-pill">
                        <flux:icon.plus class="size-4"/>
                        {{ __('Add category') }}
                    </button>
                @endunless
            </div>

            @if($showCreateForm)
                <div class="space-y-2 rounded-lg border-2 border-[var(--color-border-strong)] p-3">
                    <flux:input wire:model="newCategoryName" placeholder="{{ __('Category name') }}" size="sm"/>
                    <flux:select wire:model="newParentId" size="sm">
                        <flux:select.option value="">{{ __('Top level (no parent)') }}</flux:select.option>
                        @foreach($parentOptions as $parent)
                            <flux:select.option value="{{ $parent->id }}">{{ $parent->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex gap-2">
                        <flux:button wire:click="createCategory" variant="primary" size="sm">{{ __('Create') }}</flux:button>
                        <flux:button wire:click="$set('showCreateForm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                    </div>
                </div>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" icon="magnifying-glass" size="sm" class="sm:max-w-xs"/>
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="showHidden"/>
                    <flux:label>{{ __('Show hidden') }}</flux:label>
                </flux:field>
            </div>

            <div class="overflow-hidden rounded-lg border-2 border-[var(--color-border-strong)]">
                @forelse($categories as $category)
                    @php $pad = $isSearching ? null : 0.875 + $category['depth'] * 1.25; @endphp
                    <div wire:key="cat-{{ $category['id'] }}">
                        <div
                            @class([
                                'cat-list-row',
                                'is-active' => $selectedCategoryId === $category['id'],
                                'is-hidden' => $category['is_hidden'],
                                'cat-group-head' => ! $isSearching && $category['depth'] === 0,
                            ])
                            @style(["padding-left: {$pad}rem" => ! $isSearching])
                        >
                            <button type="button" wire:click="selectCategory({{ $category['id'] }})" class="flex min-w-0 items-center gap-2">
                                <flux:icon.chevron-right @class(['size-4 shrink-0 transition-transform', 'rotate-90' => $selectedCategoryId === $category['id']])/>
                                <span class="cat-name">{{ $isSearching ? $category['full_path'] : $category['name'] }}</span>
                            </button>
                            <span class="cat-count">{{ $category['transactions_count'] }}</span>
                        </div>

                        @if($selectedCategoryId === $category['id'])
                            <div class="space-y-3 border-b-2 border-[var(--color-border-strong)] bg-[var(--color-cib-n-50)] px-4 py-3">
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="flex-1">
                                        <label class="cib-label" for="category-name-input">{{ __('Category name') }}</label>
                                        <flux:input id="category-name-input" wire:model="editingName" size="sm"/>
                                    </div>
                                    <flux:button wire:click="saveRename" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                                    <flux:button wire:click="toggleHidden({{ $category['id'] }})" variant="ghost" size="sm" icon="eye-slash">
                                        {{ $category['is_hidden'] ? __('Unhide') : __('Hide') }}
                                    </flux:button>
                                    <flux:button wire:click="confirmDelete({{ $category['id'] }})" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-600"/>
                                </div>

                                @if($showDeleteConfirm)
                                    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3">
                                        <flux:text size="sm" class="font-medium text-red-700">
                                            {{ __('Delete') }} <strong>{{ $deletingCategoryName }}</strong>?
                                        </flux:text>
                                        @if($deletingTransactionCount > 0)
                                            <flux:text size="sm" class="mt-1 text-red-600">
                                                {{ __(':count transactions will be uncategorized.', ['count' => $deletingTransactionCount]) }}
                                            </flux:text>
                                        @endif
                                        <div class="mt-2 flex gap-2">
                                            <flux:button wire:click="deleteCategory" variant="danger" size="sm">{{ __('Confirm Delete') }}</flux:button>
                                            <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                                        </div>
                                    </div>
                                @endif

                                <flux:text size="sm" class="font-medium">{{ __('Most recent transactions:') }}</flux:text>
                                <div class="divide-y divide-neutral-200">
                                    @forelse($transactions as $transaction)
                                        <div wire:key="txn-{{ $transaction->id }}" class="flex items-center justify-between py-2 text-sm">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <flux:text size="sm" class="tabular-nums text-zinc-500">{{ $transaction->post_date->format('Y-m-d') }}</flux:text>
                                                <flux:text size="sm" class="truncate">{{ $transaction->description }}</flux:text>
                                            </div>
                                            <flux:text size="sm" class="tabular-nums font-medium {{ $transaction->direction === TransactionDirection::Debit ? 'text-red-600' : 'text-green-600' }}">
                                                {{ $formatMoney($transaction->amount) }}
                                            </flux:text>
                                        </div>
                                    @empty
                                        <flux:text size="sm" class="py-2">{{ __('No transactions for this category.') }}</flux:text>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-4 text-center">
                        <flux:text size="sm">{{ __('No categories found.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    </x-cib.card>
</div>
```

**Step 4: Run to verify pass**

Run: `ddev artisan test --filter="CategoryEditor"`
Expected: PASS (all CategoryEditor tests, including new toggle / search-path / modal-gone).

**Step 5: Commit**
```bash
git add app/Livewire/CategoryEditor.php resources/views/livewire/category-editor.blade.php tests/Feature/Livewire/CategoryEditorTest.php
git commit -m "feat(#<N>): render categories as an inline grouped accordion" \
  -m "Why: remove the Manage Categories modal; show all categories on /accounts, grouped alphabetically, full names, all management inline. Verified: ddev artisan test --filter=CategoryEditor. Refs #<N>"
```

---

## Task 4: Remove the modal trigger from AccountManager + fix its test

**Files:**
- Modify: `resources/views/livewire/account-manager.blade.php` (delete lines 204-210, the trailing `<x-cib.card>` trigger)
- Modify: `tests/Feature/Livewire/AccountManagerTest.php` (delete the `cib: manage categories trigger sits inside a cib-card` test, lines 376-383)

**Step 1: Delete the trigger card**

Remove this block from `account-manager.blade.php` (currently the last child before the closing `</div>`):
```blade
    <x-cib.card>
        <flux:modal.trigger name="category-editor">
            <flux:button variant="ghost" icon="tag" class="w-full">
                {{ __('Manage Categories') }}
            </flux:button>
        </flux:modal.trigger>
    </x-cib.card>
```

**Step 2: Delete the now-obsolete test**

Remove the whole `test('cib: manage categories trigger sits inside a cib-card', ...)` block (lines 376-383) from `tests/Feature/Livewire/AccountManagerTest.php`.

**Step 3: Run to verify pass**

Run: `ddev artisan test --filter="AccountManager"`
Expected: PASS (no reference to "Manage Categories" remains).

**Step 4: Commit**
```bash
git add resources/views/livewire/account-manager.blade.php tests/Feature/Livewire/AccountManagerTest.php
git commit -m "refactor(#<N>): drop the Manage Categories modal trigger" \
  -m "Why: categories now render inline on /accounts. Verified: ddev artisan test --filter=AccountManager. Refs #<N>"
```

---

## Task 5: CSS — untruncated names, depth indent, group headers, chevron

**Files:**
- Modify: `resources/css/app.css` (`.cat-list-row .cat-name`, lines 629-635; add group-head rule)

**Step 1: Stop truncating names**

Replace the `.cat-list-row .cat-name` rule (lines 629-635):
```css
    .cat-list-row .cat-name {
        font: 600 14px/1.3 var(--font-sans);
        min-width: 0;
        overflow-wrap: anywhere;
    }
```
(Removes `overflow: hidden; text-overflow: ellipsis; white-space: nowrap;` so long/nested names wrap instead of truncating — satisfies "full category name is shown".)

**Step 2: Add group-header emphasis** (after the `.cat-name` rule, inside the same `@layer components`):
```css
    .cat-group-head .cat-name {
        font-weight: 900;
    }
```

**Step 3: Build assets and eyeball**

Run: `ddev exec npm run build`
Then load `/accounts` in the browser (logged in, DB seeded): confirm top-level categories are bold headers, sub-categories indent one tier, grandchildren indent two, long names wrap, counts align right, chevron rotates on expand, search flattens to full-path rows.

**Step 4: Commit**
```bash
git add resources/css/app.css
git commit -m "style(#<N>): untruncated wrapping names + grouped accordion styling" \
  -m "Why: full names must be visible; depth headers/indent for the inline category accordion. Refs #<N>"
```

---

## Task 6: Full verification (quality gates + smoke)

**Step 1: Pint**

Run: `ddev exec ./vendor/bin/pint --dirty`
Expected: no style violations (auto-fix any, then re-commit if changed).

**Step 2: PHPStan**

Run: `ddev exec ./vendor/bin/phpstan analyse`
Expected: no errors (tests/ excluded).

**Step 3: Targeted tests**

Run: `ddev artisan test --filter="CategoryEditor|AccountManager|CategoryTest"`
Expected: all PASS.

**Step 4: Manual smoke on `/accounts`**
- Categories appear alphabetically, grouped by top-level, sub-categories ordered, 3-level nesting indented.
- Expand a category → recent transactions show inline; collapse on re-click.
- Rename saves; hide toggles (and "Show hidden" reveals hidden); delete shows the transaction-count warning and uncategorizes; "Add category" creates under the chosen parent.
- Search flattens to full-path matches; clearing restores the grouped tree.
- No "Manage Categories" button and no modal remain.

**Step 5:** If any gate fails, fix and amend the relevant task's commit.

---

## Task 7: Pull request

**Step 1:** Push and open the PR:
```bash
git push -u origin feat/<N>-inline-categories
```
**Step 2:** Open a PR **targeting `develop`**, add labels (`frontend`, `enhancement`), request **Copilot** as reviewer. PR body: what/why + verification (Pint, PHPStan, tests, manual smoke). Footer: `Refs #<N>` / `Verified: <status>`.

**Step 3:** Address review. If a Copilot suggestion is a false positive, refute with evidence rather than applying. Squash-merge into `develop`. Close issue `#<N>` manually (PRs to `develop` don't auto-close).

---

## Notes / gotchas
- `depth()` walks `parent`; `render()` eager-loads `parent.parent`, so no N+1 for ≤3 levels.
- Sort is `Str::lower(fullPath())` with `SORT_NATURAL` → case-insensitive DFS alphabetical; this is exactly "group by category, order sub-categories".
- Search intentionally abandons grouping and shows full paths (unambiguous results); grouped tree returns when the box clears.
- No Playwright/browser test covers the old category modal (`tests/Browser/Livewire/*Category*` are for the transaction combobox and spending chart), so none needs updating. Grep `tests/Browser` for `/accounts` before finishing to be safe.
- Out of scope: reparenting/drag-reorder, bulk actions, colour/icon editing, account CRUD changes.
