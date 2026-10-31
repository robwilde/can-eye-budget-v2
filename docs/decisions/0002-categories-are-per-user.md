# 0002. Categories and category groups become per user

- **Status:** Accepted
- **Date:** 2026-10-31
- **Issue:** #722 (epic #641, plan feature F6, slice `F6-spike`)
- **Builds on:** ADR 0001 (#642): data stays per user, with no household
- **Plan:** `docs/plans/2026-10-21-sure-feature-inventory.md`, section F6

## Context

`categories` and `category_groups` have no `user_id`. Every user reads and writes the same rows.

- `CategoryEditor` is the only UI that writes them. Its `createCategory`, `saveRename` (rename and re-parent), `saveCategoryGroup`, `saveGroup`, `toggleHidden` and `deleteCategory` methods write the shared rows. They have no ownership or admin check beyond the `auth` and `verified` middleware on the `accounts` route. One user's rename, hide or delete changes every user's tree. The one other runtime write is `MonthEndBalanceRuleProvisioner::balanceCategory()`, below.
- `deleteCategory` is a bare `Category::delete()`, and the foreign keys do the rest:
  - `category_id` is set to null on `transactions`, `transaction_splits`, `budgets`, `planned_transactions` and `bnpl_orders`, and on both `payees` category columns.
  - `user_category_budget_tags` rows are cascade-deleted.
  - `transactions.category_source` stays set. The foreign key bypasses the `saving` hook that keeps the source null whenever the category is null.
  - Category ids are left dangling in `user_rules` triggers and actions, in the `analysis_suggestions` and `bnpl_order_events` payloads, and in the `payees.probabilities` keys.
  - The confirm step counts only the acting user's transactions (`CategoryAttribution::query(auth()->id())`), even though the delete reaches every user.
- `is_hidden` is one boolean on the shared row, so hiding a category hides it for everyone. `MonthEndBalanceRuleProvisioner::balanceCategory()` un-hides `Balance` for everyone whenever a user registers.
- `UserCategoryBudgetTag`, unique on `(user_id, category_id)`, is the one per-user overlay today. It exists because "categories are shared, so the override cannot live on the category row". `BudgetTagResolver::forCategory` checks the payee override first, then the user's override on the root category, then the root's own `budget_tag`.
- Every row that references a category is user-owned:
  - Six tables carry `user_id`: `transactions`, `budgets`, `planned_transactions`, `bnpl_orders`, `payees` and `user_category_budget_tags`.
  - `transaction_splits` is scoped through `transactions`, and `bnpl_order_events` through `bnpl_orders`.
  - `user_rules` and `analysis_suggestions` carry `user_id` and hold category ids in JSON.
- 30 files in `app/` query `Category` or `CategoryGroup`, or validate ids against `categories`, without a user filter. Several places find a category by name rather than by id:
  - `PayCycleConfigurator` finds `Salary` under `Income`.
  - `RuleMiningStage` uses the path `Income / Salary`.
  - `Transfer` and its children are matched by `ReportAggregator::transferCategoryIds()`, `Transaction::scopeExcludingTransfers()`, `PlannedTransaction::scopeExcludingTransfers()` and `TransferSignal`.
  - `MonthEndBalanceRuleProvisioner` uses `Balance`.
  - `PayeeReview::WORK_ROOTS` and `PERSONAL_ROOTS` name five roots (`Software & Online Services`, `Work Equipment`, `Personal & Shopping`, `Entertainment`, `Learning & Reading`). `PayeeReview` and `TransactionList::narrowedByIntent()` use them to narrow the pickers.
  - `CategorySeedRules` resolves full paths.
  - `CategoryGroupProvisioner` assigns groups by root name.
- The tree comes from two places:
  - `CategorySeeder` defines 87 categories, three levels deep. Names are unique among siblings but not across the tree (`Motorcycle` and `Subscription` each appear twice), so only a full path names exactly one category. It is not idempotent and does not run on deploy.
  - `CategoryGroupProvisioner` adds 13 groups.
  - A newly registered user gets no categories of their own; they see the shared ones.
- No import path creates categories today. `CategorySeedRules` and the BNPL importer only look categories up.
- There is no admin concept beyond the `viewHorizon` email allowlist. F48 plans an `is_admin` flag.
- The F44 preference store exists: `users.preferences` (JSON) is cast to the `UserPreferences` DTO, and `User::updatePreferences()` merges keys under a row lock.

ADR 0001 says that a user-specific change to shared reference data "never writes to the global row". `CategoryEditor` breaks that rule today. Merge, replace-on-delete, rename and a lock (F6) would break it further.

## Options weighed

1. **Keep global categories, with admin-only merge, replace-on-delete, rename and lock.**
   - For: no migration, and one tree to maintain.
   - Against: a merge or a replace-on-delete would rewrite every user's Transaction, Budget and PlannedTransaction rows. ADR 0001 rules that out: no login can change another login's data, and instance admin "does not widen any per-user query over ledger data".
   - Against: ordinary users would lose the create, rename and hide they have today.
   - Against: no admin flag exists until F48.
2. **Per-user categories: `user_id` on `Category` and `CategoryGroup`.**
   - For: every F6 action rewrites only the acting user's rows.
   - For: it matches Sure's per-family tree, since a family is one user here (ADR 0001).
   - For: it follows C2 and ADR 0001's scoping rule without exceptions.
   - For: import mapping (F30) can create the categories it needs.
   - Against: a one-off migration clones the tree and rewrites references.
   - Against: the 30 files gain a user filter, and `CategoryFactory` gains a user, which touches the 84 test files that use it.
3. **Global categories with a per-user overlay.** Users would keep their own rows for name, hidden, colour, icon, parent, group and "merged into", plus user-owned additions.
   - For: one shared default tree, extending the `UserCategoryBudgetTag` precedent.
   - Against: every reader has to resolve two layers for name, parent, full path, hidden flag and group. That means the same 30 files, plus every place that builds a full path.
   - Against: a category a user creates is per user anyway, so the app would carry a per-user tree and an overlay at the same time.
   - Against: sibling uniqueness and full-path lookups (`CategorySeedRules`, the F28 rule export) would have to span both layers.
   - It costs more than option 2 and gives the user nothing extra.

## Decision

**Option 2. `Category` and `CategoryGroup` become per user.** Each table gets a `user_id`, declared as ADR 0001's scoping rule requires, and every query filters on it explicitly (C2). Every user owns a full tree and a full set of groups. There is no shared default row and no overlay.

Reasons:

1. Every row that references a category is already user-owned. Per-user categories make the category follow its references, so no action on a category can reach another user's ledger.
2. It removes today's cross-user writes in `CategoryEditor` (rename, hide, delete, group edits) as well as preventing new ones.
3. Option 3 needs per-user rows too (for user-created categories) and adds a resolution layer on top. Option 1 needs an admin role that ADR 0001 does not allow over ledger data.

Groups move with categories. `category.group_id` would otherwise point at a shared row that any user's `saveGroup` could rename. The `category_groups.name` unique index becomes unique on `(user_id, name)`.

Category names are unique among siblings within one user's tree, so a full path names exactly one category. This is checked in validation, not by an index: a unique index over `(user_id, parent_id, name)` lets duplicate root names through, because `parent_id` is null for roots and both MariaDB and SQLite treat nulls as distinct.

## Who may act, and which rows each action rewrites

Only the owning user acts on their own categories and groups. There are no roles and no guests (ADR 0001). The instance admin flag from F48 gives no access to another user's categories.

Every action loads its source, target and replacement categories with `where('user_id', $user->id)` and refuses any id outside that set. Every rewrite also filters on the acting user explicitly (C2), even though only that user's rows can reference their categories.

| Action | Rows it may rewrite (all owned by the acting user) |
|---|---|
| Create | Inserts one category. |
| Rename, colour, icon, hide, set group | That category only. Changing a parent's colour also recolours its subcategories (F6). No ledger rows change. |
| Re-parent | That category's `parent_id` only. |
| Merge sources into a target | Sources are merged into the target one at a time, each against the target's state after the previous one. `Transaction.category_id`, with `category_source` kept. `TransactionSplit.category_id`, through the user's transactions. `Budget`: a source line in a `budget_plan_id` where the target already has a line is folded into it and deleted. `limit_amount` stays null (Shared) only when both are null; otherwise the two are summed with null as 0. `rolled_over_amount` is summed, and the target keeps its own `rollover_enabled`. A source line in a plan with no target line is re-pointed. `PlannedTransaction` and `BnplOrder` `category_id`. `Payee` `suggested_category_id`, `confirmed_category_id` and the `probabilities` keys. `UserCategoryBudgetTag`: the source row is dropped where the target already has one (including one re-pointed from an earlier source), otherwise re-pointed. `UserRule` triggers (`field` `category_id`) and actions (`set_category`). `AnalysisSuggestion` payload `category_id` (recurring suggestions) and the `set_category` values inside its `actions` copy (rule suggestions). `BnplOrderEvent` payload `category_id` and `previous_category_id`, through the user's BNPL orders. The recent-picks list (below). The sources' subcategories move under the target, then the sources are deleted. |
| Delete with a replacement | The same rewrites as a merge, with the replacement as the target. The deleted category's subcategories become top-level, as in F6. |
| Delete without a replacement | Clears `category_id` and `category_source` together on the user's transactions. Clears `category_id` on splits, budget lines, planned transactions, BNPL orders and both payee columns, and drops the id from `probabilities`. Deletes the user's `UserCategoryBudgetTag` row. In rules, removes `set_category` actions and `category_id` triggers that name the category. A rule is deactivated, never widened, when it has no actions left, or when it is in strict mode (every trigger must match) and lost a trigger. A non-strict rule ORs its triggers, so a removed trigger could never have matched again. In rule suggestions, removes `set_category` entries that name the category from the `actions` copy, and dismisses a suggestion left with none. Sets the other payload keys that name the category to null. Subcategories become top-level. |

The taxonomy-remap migration's `merge()` is the reference list of what to rewrite, but it is not complete for this purpose. It predates the `budgets` unique key on `(budget_plan_id, category_id)` and does not sum clashing lines. It does not rewrite `payees.probabilities` keys or the `set_category` values inside `AnalysisSuggestion` payload `actions`. It does not touch `category_source`. The F6-svc `CategoryMerger` must cover all of these, in one DB transaction (C8).

Moves within the tree keep it a tree with unique sibling names:

- A merge whose target is a source or sits inside a source's subtree is refused, as `CategoryEditor::wouldCreateCycle()` already refuses for re-parenting.
- When a moved subcategory (under a merge target) or a promoted one (to top level on delete) has the same name as a category already under its new parent, it is merged into that sibling, recursively, as the remap migration's `reparent()` does.

The categories that code finds by name are guarded: renaming, re-parenting, merging away or deleting them is refused. They are `Income`, `Income / Salary`, `Transfer`, `Balance`, and the five roots in `PayeeReview::WORK_ROOTS` and `PERSONAL_ROOTS`. Breaking one now affects only the acting user, but it still silently breaks something for that user: pay-cycle detection, transfer exclusion and detection, the month-end balance rule, or the work and personal picker narrowing. The guard can be removed once those lookups use a stable key instead of a name.

## Category lock

The lock is a **per-user setting**: a `categoriesLocked` boolean in `UserPreferences` (the F44 store), default false. The user switches it from settings through an audited action (C3).

It is not an app-wide `budget.*` flag, for three reasons:

- C6 flags switch optional features on for the whole install, default off.
- An instance flag would lock every user's own tree at once, the same cross-user effect this decision removes.
- With no household (ADR 0001, #642) there is no admin-versus-member split for an instance flag to express.

While a user's lock is on, creating a category in that user's tree is refused, with the "locked" message, from:

- the CategoryEditor form
- the transaction category picker (F6-ui)
- loading the default categories
- the assistant (F41)
- the API (F42)

The lock covers creation only, as in Sure. Rename, merge, delete and hide stay available.

**Import category mapping still creates categories.** When an import maps an unknown category to "Add as new" (F30's CSV mapping, for example), the category is created in the importing user's tree even while the lock is on. Registration provisioning and the scoping migration also create categories regardless of the lock. The lock is checked in the one service method that user-facing creates go through; import mapping and provisioning do not call that check.

The lock does less than in Sure, where it stops other family members. Here it stops accidental creates from the picker and creates from automated callers such as the assistant and API tokens.

## Recently used picks

Each user's recent picks are stored in `UserPreferences` as `recentCategoryIds`: up to four category ids, most recent first, with no duplicates. They are written through `User::updatePreferences()` when the user saves a category they chose by hand in a category picker for a ledger row. That happens in:

- TransactionModal (`x-category-combobox`)
- TransactionList: split save and bulk apply (`x-category-combobox`)
- PlannedTransactionManager (`x-category-combobox`)
- PayeeReview (`flux:select`)
- BnplOrderReview (`x-category-combobox`)
- AnalysisSuggestions (`x-category-combobox`)
- RecurringTransactionReview (`x-category-combobox`)

The list is never written by rules, imports or feed sync, payee suggestion, category propagation, BNPL fan-out, the filter pickers or the rule builder.

When the list is read, it is filtered to the user's own visible categories, so deleted or hidden ids drop out. A merge replaces the source ids with the target id inside its transaction, then removes later duplicates.

The picks are not derived from `transactions.category_source = manual`, because:

- `Manual` is also written by automated paths: `BnplPaymentFanout`, `PropagatePlannedTransactionCategory`, `PropagateTransactionCategory` (which copies a `Manual` source onto every sibling in the planned group), and the `saving` hook's default when `createChild` changes a category without passing a source.
- `createChild` gives every new version fresh timestamps, so editing a transaction's notes would make an old pick look recent.
- Splits and planned transactions have no source column.
- No index covers `category_source`.

## Migration path

Scoping changes, so existing categories, groups and the rows that reference them move. The existing global-era migrations (the taxonomy remap, the `Balance` provisioning, the month-end rule reconcile and the group provisioning) keep their timestamps and still run against global rows before these. Three of them call live services: `MonthEndBalanceRuleProvisioner::provisionAllUsers()` and `CategoryGroupProvisioner::provision()`. Those services become per user, and at those timestamps `categories.user_id` does not exist yet. So the scoping slice first copies the services' current global-row logic into those migrations, then changes the services. Three new migrations follow them, in this order:

1. **Schema.**
   - Add a nullable `user_id` to `categories` and `category_groups` with `constrained()->cascadeOnDelete()`, plus an index that starts with `user_id`.
   - Replace the `category_groups.name` unique index with a unique index on `(user_id, name)`.
2. **Data.**
   - If there are no users, delete the global rows. A user registered later gets the default tree.
   - Otherwise, give every user except the lowest-id user a clone of the groups and the category tree:
     - Copy parents before children, and keep old-to-new id maps for categories and for groups.
     - Copy `name`, `budget_tag`, `icon`, `color`, `is_hidden` and the `anzsic_*` columns. Set `parent_id` and `group_id` through the category and group maps, so a clone never points at another user's rows.
     - Rewrite that user's references through the map, using the full list from the merge row above.
   - Finally, set `user_id` on the remaining global rows to the lowest-id user. That user's references are unchanged, so a single-user install rewrites no ledger rows at all.
   - Each user is handled in one DB transaction. A user who already owns categories is skipped, so a run that fails part way can be restarted. Global rows stay in place until every clone exists.
3. **Schema.** Make `user_id` not null on both tables.

How it runs on each engine:

- **Schema and data stay in separate migrations.** MariaDB commits implicitly on DDL, so a transaction there cannot span schema and data, while SQLite would roll both back. Mixing them would behave differently on the two engines.
- **The data migration is engine-neutral.** It uses only the query builder (`chunkById`, `insertGetId`, `update`), like the taxonomy-remap migration. JSON is rewritten in PHP with `json_decode` and `json_encode`, keeping each value's int or string shape. No JSON SQL functions are used, and nothing branches on the driver.
- **Step 3 uses `->change()`.** That is an `ALTER TABLE` on MariaDB and a table rebuild on SQLite, which Laravel 12 performs keeping the foreign keys.
- **Identifier names stay at or under 64 characters.** `MigrationIdentifierLengthTest` checks this against the MariaDB grammar.
- **Testing.** The Pest migration test runs on SQLite (C7). No test runs on MariaDB (C8), so the data migration is also run once on a copy of the DDEV database before release.
- **Rollback.** The data migration's `down()` is a no-op, like the taxonomy remap. Collapsing per-user trees back into one would mean choosing one user's edits over the others', so restore from a backup instead. Step 3 reverses normally. Step 1 reverses only before anything has been cloned: after that, restoring the unique index on `category_groups.name` fails on the cloned group names, so restore from a backup.

New users get their tree from a provisioner that takes over `CategorySeeder`'s definitions and `CategoryGroupProvisioner`'s group maps. `CreateNewUser` has no DB transaction today: it calls `User::create` and then `MonthEndBalanceRuleProvisioner::provisionForUser()`. The scoping slice wraps `User::create`, the category provisioner and `provisionForUser()` in one `DB::transaction`, provisioning categories first because the rule needs the user's own `Balance`. That is the wrap F46 already plans.

`DatabaseSeeder` creates the first user before calling `CategorySeeder` and the sandbox users with `User::factory()` afterwards, which bypasses `CreateNewUser`. It changes to create all its users first, then provision a tree for each, before `provisionAllUsers()`. Tests that seed `CategorySeeder` with no user (`CategorySeederIconsTest`, `SetupJourneyTest`) create a user first or call the provisioner directly.

`ProvisionCategoryGroupsTest` and `RemapCategoriesTaxonomyTest` run a global-era migration's `up()` against the fully migrated schema. Those migrations insert rows with no `user_id`, which fails once step 3 makes the column not null. Both tests are retired, and the scoping-migration test covers the path from global rows to per-user rows instead.

## Impact

- **CategoryEditor.** Every query and write is scoped to the user, and the `exists:categories,id` and `exists:category_groups,id` rules gain a `user_id` condition. `deleteCategory` moves onto the `CategoryMerger` paths above, so the delete no longer reaches other users. The confirm's count still covers only the user's current transactions, not the budget lines, plans, BNPL orders, payees and rules the delete also rewrites. Group names are unique per user.
- **Budget.** Lines key on `(budget_plan_id, category_id)` as today. A category now belongs to the line's user, so a plan can only hold that user's categories. Merge sums clashing lines as described above. `Budget::remaining()` is unchanged.
- **PlannedTransaction.** `category_id` points at the owner's category. `PlannedTransactionManager::resolvedCategoryId()` and BNPL recategorise look the category up within the user's tree.
- **UserRule.** Trigger and action ids are rewritten by the scoping migration and by merge. `RuleActionLookups` and `RuleActionExecutor` resolve ids within the rule owner's tree, so a rule can never set another user's category. `MonthEndBalanceRuleProvisioner` resolves the user's own `Balance`, and `RuleMiningStage` the user's own `Income / Salary`.
- **Payee.** `suggested_category_id`, `confirmed_category_id` and the `probabilities` keys point at the owner's categories. `PayeeSuggester`, `PayeeConfirmer` and `PayeeReviewQueue` read the user's tree.
- **Taxonomy-remap migration.** Unchanged. It runs before the scoping migrations, on global rows. On a fresh install it returns early, because the table is empty. No app code calls its `merge()`. `CategoryMerger` reimplements the rewrite per user, with the gaps listed above closed.
- **Other readers.** All of these take the user:
  - the pickers (`Category::visibleSortedByFullPath()`, `allWithLinkedParents()`)
  - `CategoryGroupMap`, `CategoryGroupReport`, `CategoryReport`, `CashFlowLoader`, `DayActivityLoader` and `ReportAggregator`
  - `CategorySeedRules`, `CategoryRuleMiner` and `PayCycleConfigurator`
  - `ConfirmBudgetTags` (its root list and `setTag`)
  - the `Budget` creating hook that names a line after its category
  - the transaction validation rules and the two console commands

  The `Transfer` scopes on `Transaction` and `PlannedTransaction`, `TransferSignal`, and the `PayeeReview` and `TransactionList` intent narrowing match by name through a relation or a user-filtered list, so they keep working on the user's own tree.
- **UserCategoryBudgetTag.** It is kept, and the migration re-points it like any other reference. It is now redundant with the user's own `categories.budget_tag`, so folding it in is a follow-up.
- **Hidden categories.** `is_hidden` becomes per user without a new column. `MonthEndBalanceRuleProvisioner` un-hides only the registering user's `Balance`.
- **ADR 0001.** Its reference-data rule no longer lists `Category` and `CategoryGroup`. The F40 `Security` stays global reference data.

## Consequences

- F6-svc (#723) and F6-ui (#724) build on per-user categories. The scoping migration and the query scoping above come first, as their own slice (#830), and #723 is blocked by it.
- #723's lock is the per-user `categoriesLocked` preference, not a `budget.categories_locked` flag.
- #724's recent picks come from `recentCategoryIds`, not from `CategorySource::Manual`.
- The plan's F3, F21, F28, F30, F40 and F43 notes that relied on global categories are amended to match.

## Follow-ups

| Item | Decision |
|---|---|
| Scoping migration and per-user queries | Raised as #830, which blocks #723. |
| Fold `UserCategoryBudgetTag` into the user's `categories.budget_tag` | Raise after the scoping slice. `BudgetTagResolver` and `ConfirmBudgetTags` move with it. |
| Stable keys for the name-matched categories (`Income / Salary`, `Transfer`, `Balance` and the five intent roots) | Not raised. The guard above stands until a feature needs to rename them. |
