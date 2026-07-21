# Allocating $0.00 transactions to a category

**Issue:** #349
**Date:** 2026-07-21
**Status:** Approved

## Problem

`$0.00` transactions — e.g. a bank statement's `0.00 Purchases - Month End Balance: $4,085.57`
marker row — cannot be allocated to a category. Three distinct blockers:

1. **Import drops them.** `CsvParserService::parseAmount` treats a `$0.00` value in a
   Debit/Credit column mapping as "no amount" and the row is skipped. A single
   signed-amount column keeps it (amount `0`, direction Credit).
2. **Manual allocation is blocked.** `TransactionModal::resolveTransactionWithParsedAmount`
   rejects `amount <= 0` with *"The amount must be greater than zero"*, so an existing
   `$0.00` transaction cannot be opened and (re)categorised.
3. **No auto-category.** Rule mining explicitly skips `Purchases - Month End Balance`
   rows, and no "Balance" category exists in the taxonomy.

## Approach

Approach A (approved): a data migration provisions the category and the per-user
auto-categorisation rule, so it works on the next import after deploy with no manual
command and no environment-specific hardcoded ids. Categorisation stays on the single
existing path — the `UserRule` pipeline (`UserRulesStage`), which already runs after
every CSV import via `RunTransactionAnalysisJob`.

## Changes

### 1. Import — stop dropping explicit `$0.00` rows

`CsvParserService::parseAmount`, Debit/Credit branch. Distinguish *absent* from
*explicit zero*:

- Both cells blank (`normalizeAmount` → `null`) → return `[null, ...]` (row still skipped).
- Either cell present but both resolve to `0` → return `[0, TransactionDirection::Credit]`
  (row kept).
- Non-zero credit / debit → unchanged.

Direction `Credit` for zero matches the single signed-amount-column path (`0 < 0` is
false → Credit). A `$0.00` amount is reconciliation-safe: `ReconciliationPolicy`'s 10%
tolerance of `0` is `0`, so it can only match a (non-existent) `$0.00` plan.

### 2. New top-level "Balance" category

- `CategorySeeder`: add a `Balance` root category (icon `building-library`), for fresh
  installs. Categories are global (no `user_id`).
- Idempotent migration inserts `Balance` by name into the existing database if absent.

### 3. Auto-categorisation rule

Idempotent migration, after ensuring the category exists:

- Resolve the `Balance` category id by name.
- For each existing user: find-or-create the `Auto-categorisation` `UserRuleGroup`, then
  create (if absent) a `UserRule`:
  - trigger: `description` `contains` `"Month End Balance"` (bank-agnostic)
  - action: `set_category` → Balance id
  - `strict_mode` true, `is_auto_apply` true, `is_active` true
- `DatabaseSeeder` performs the same rule provisioning for fresh installs (after users
  are seeded).

`UserRulesStage.autoApplyRule` files every future month-end-balance row on import.

### 4. Lift the `$0.00` manual-allocation block

`TransactionModal`:

- `resolveTransactionWithParsedAmount`: guard `amount < 0` (allow zero, still reject
  negatives) — used by the plain edit path so any existing `$0.00` transaction can be
  re-categorised.
- `convertToTransfer` shares that resolver, so add an explicit `amount <= 0` guard there
  (a transfer needs a positive amount).
- Keep `<= 0` on `createTransaction`, `createTransfer`, `createPlannedTransaction`,
  `resolvePlannedTransactionWithParsedAmount`, `resolveTransferPairWithParsedAmount`
  (no manual creation of `$0.00` transactions, transfers, or plans).

## Verification

- `CsvParserService`: a `$0.00` Debit/Credit row is imported; a blank/blank row is still
  skipped; normal debit and credit rows are unchanged.
- Migration/seeder: `Balance` category present; each user has an active auto-apply
  `Month End Balance` → Balance rule; running twice is idempotent.
- Pipeline: a `$0.00` `Purchases - Month End Balance` transaction ends with
  `category_id = Balance` after `TransactionAnalysisPipeline` runs.
- `TransactionModal`: editing a `$0.00` transaction to set a category succeeds (no
  "amount must be greater than zero" error).

## Out of scope

- Changing how the statement closing balance is captured (already handled at CSV mapping).
- Hiding `$0.00` rows from reports (they contribute `$0` and are harmless).
