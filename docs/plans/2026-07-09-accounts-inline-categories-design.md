# Inline categories on the Accounts page

**Date:** 2026-07-09
**Status:** Approved (design)
**Area:** `/accounts` — category management UX

## Context

The `/accounts` page hides all category management behind a **"Manage Categories"** modal:

- `resources/views/accounts.blade.php` renders `<livewire:account-manager />` + `<livewire:category-editor />`.
- `AccountManager` shows accounts and a bottom card with a `flux:modal.trigger name="category-editor"` button.
- `CategoryEditor` (`app/Livewire/CategoryEditor.php` + `resources/views/livewire/category-editor.blade.php`) is a two-panel `flux:modal`: left = searchable flat list (`fullPath()` + transaction count, "Show hidden" toggle, "Add category" form); right = selected category's rename / hide / delete + its 50 most recent transactions.
- Categories are hierarchical up to **3 levels** (`parent_id`, seeded from ANZSIC in `CategorySeeder`), e.g. `Office / Software`, `Office / Training / Subscription`, `Transport / Motorcycle / Fuel`.
- The list currently sorts by `orderByDesc('transactions_count')`.

### Problem

Categories are buried in a modal, sorted by usage (not discoverable), and the hierarchy is only visible as a flat `A / B / C` string.

## Decision

Remove the modal. Surface **all** categories directly on the Accounts page as a one-column, mobile-first grouped accordion, sorted alphabetically (group by top-level category, sub-categories ordered within), with full names never truncated. **All existing management is retained** — rename, hide, delete (with transaction-count warning), create, and per-category recent transactions — now inline instead of behind a popup.

### Requirements (from the user)

1. Remove the "Manage Categories" modal; show categories on the page.
2. Ensure the full category name is shown (never truncated).
3. Alphabetical order, grouped by category, sub-categories ordered.
4. (Clarified) Keep all management actions inline.
5. (Clarified) One-column grouped accordion, mobile-first.
6. (Clarified) Nested rows use indent + own segment name, never truncated.

## Approaches considered

- **A — Convert `CategoryEditor` in place (chosen).** Keep the component and its test seam; rewrite its Blade view from a modal into an inline card accordion; delete the trigger button in `account-manager.blade.php`. Handler methods are untouched; only `render()`'s sort and the markup change. Smallest blast radius, reuses tested logic, keeps account vs category concerns separate.
- **B — Merge into `AccountManager`.** Rejected: bloats an already-busy component and discards the clean test seam for large churn.
- **C — Read-only display + keep modal for edits.** Rejected: contradicts "remove the modal".

## Design (Approach A)

### Structure

An `<x-cib.card>` "Categories" section rendered under the accounts list:

- Header: "Categories" heading + "Add category" button (matches the `.cib-yellow-pill` used by "Add Account").
- Controls: search input + "Show hidden" toggle (retained; ~100 seeded categories make search useful).
- Grouped accordion list.

### Grouping & sort

Replace `orderByDesc('transactions_count')` with a depth-first alphabetical walk. Sorting the visible set by `Str::lower(fullPath())` (natural order) yields the requested order directly:

```
Office
  Office / 3D Printing
  Office / AI Apps
  Office / Hardware
    Office / Hardware / Rentals
  Office / Software
Personal
  Personal / Finance
    Personal / Finance / Bank Fees
...
```

Each row carries a computed `depth` (0/1/2) for indentation. Depth-0 rows render as bold group headers; children and grandchildren indent progressively. Reuses the `fullPath()` logic behind `Category::visibleSortedByFullPath()`.

### Nesting (3 levels) & full name

Grandchildren render as a third indent tier under their sub-category. Per requirement 6, each row shows **its own segment name only**; hierarchy is conveyed by indentation and grouping, and names **wrap instead of truncating** so the full name is always visible.

### Management (inline, no modal)

Per row: transaction-count badge; pencil → inline name input + save/cancel (`saveRename`); eye-slash → `toggleHidden`; trash → inline confirm strip with the transaction-count warning (`confirmDelete` / `deleteCategory`). Clicking a row toggles `selectedCategoryId` and expands its 50 most recent transactions inline beneath it (reuses `$transactions`). "Add category" reveals the existing name + parent-select form.

### Search behavior

While searching, drop grouping and render a flat list of matches showing the full path (unambiguous results). The grouped accordion returns when the search clears.

## Affected files

- `resources/views/livewire/category-editor.blade.php` — rewrite modal → inline accordion card.
- `app/Livewire/CategoryEditor.php` — change `render()` sort to DFS-alphabetical; add per-row `depth`; keep all handlers.
- `resources/views/livewire/account-manager.blade.php` — remove the "Manage Categories" trigger card.
- `resources/css/app.css` — add/adjust `@layer components` styles for the accordion rows/indent (adapt existing `.cat-list-row`).
- `resources/views/accounts.blade.php` — unchanged (keeps `<livewire:category-editor />`, now inline).

## Testing

- Update `tests/Feature/Livewire/CategoryEditorTest.php`: replace "categories sorted by transaction count descending" with an alphabetical-grouped-order assertion.
- Update `tests/Feature/Livewire/AccountManagerTest.php` (~line 382): the `assertSee('Manage Categories')` trigger assertion is removed.
- Add: DFS grouped order, full path/name visible for a 3-level category, accordion expand reveals that category's transactions, inline rename/hide/delete/create still work, search flattens to full-path results.
- Existing method-level `CategoryEditor` tests keep passing (handlers unchanged).
- Grep for any Playwright E2E referencing the category modal and update if present.

## Out of scope

- Drag-and-drop reordering / reparenting of categories.
- Bulk actions.
- Changing category colours/icons.
- Any change to account CRUD.

## Conventions

- Brand: sentence case, Lato, no emoji, light-only, black 2px borders, yellow accent used sparingly (live tokens in `resources/css/app.css`).
- Money: tabular-nums, signed where sign matters.
- Workflow: issue-first; feature branch named with the issue number; PR targets `develop`; Pint + PHPStan via GrumPHP before tests. This design doc is committed directly to `develop` per the docs convention.
