# 0003. Splits are child transactions on split_parent_id

- **Status:** Accepted
- **Date:** 2026-11-01
- **Issue:** #784 (epic #641, plan feature F4, slice `F4-split`)
- **Plan:** `docs/plans/2026-10-21-sure-feature-inventory.md`, section F4

## Context

Today a split is a set of `TransactionSplit` category lines on one transaction. Each line has a category, an amount and notes. Rules, bulk category, `UserRuleApplier` and `CategoryAttribution` read splits through the `splits` relation.

F4 asks for something else (inventory lines 319, 328-329 and 349):

- A split parent has many child transactions. Each child has its own name, amount, category, notes and excluded flag.
- Children share the parent's date, merchant and kind.
- The parent is excluded while split.
- Lines that do not add up to the parent amount split nothing.

Replacing child transactions with extended `TransactionSplit` lines would be a change to that spec.

`parent_transaction_id` already has a meaning. `Transaction::createChild()` writes it to link each edit to the version before it. These readers depend on that meaning:

- `Transaction::lockCurrentVersion`
- `TransferLinker`
- `PendingMergeService`
- `FirstImportSummary`
- `AccountBalanceHistoryBuilder`

A split child on that column would read as an edit history.

`AccountBalanceHistoryBuilder` keeps excluded rows in the account balance. #828 pinned this. Excluding the parent alone would therefore count the money twice: once in the parent, once in the children.

## Options weighed

1. **(a) Extend `TransactionSplit` with name, notes and excluded.**
   - For: no new column, and the readers of `splits` keep working.
   - Against: a line is not a transaction. It has no date, account or status of its own, so reports, search and the list would need a second path for it.
   - Against: it changes what F4 specifies.
2. **(b) Child transactions on `parent_transaction_id`.**
   - For: no new column.
   - Against: every reader of version lineage would treat a child as an earlier or later version of the parent. `lockCurrentVersion` would resolve to the wrong row.
3. **(c) Child transactions on a new `split_parent_id` column.**
   - For: children are real transactions, so every existing reader counts them.
   - For: version lineage stays untouched.
   - Against: two split mechanisms exist at once (see Consequences).

## Decision

**Option (c). Children are `Transaction` rows on a new `split_parent_id` column, beside `TransactionSplit`.**

Schema:

- `transactions.split_parent_id` is a nullable foreign key to `transactions`, with `cascadeOnDelete`. The migration is `2026_11_01_000000_add_split_parent_id_to_transactions_table.php`.
- The cascade applies to a hard delete. Soft deletes are handled in code (see What cannot be split).
- Children are full `Transaction` rows. They share the parent's account, date, direction, status, merchant and currency (`TransactionSplitter::createChildren()`). The app has no transaction kind column, so F4's "kind" maps to nothing here.
- `Transaction::splitParent()`, `splitChildren()`, `isSplitParent()` and `isSplitChild()` read the column.

Sitting beside `TransactionSplit`:

- There is no data migration. Existing category lines stay as they are.
- A row with category lines cannot be split into children.
- A split parent or split child cannot get category lines.

### Money counted once

- The parent is excluded. Spend, budgets, reports and `CategoryAttribution` count the children.
- The parent alone stays in the account balance, because excluded rows stay there.
- `AccountBalanceHistoryBuilder` and `AccountValuationService` skip rows with `split_parent_id`, so the children are never added on top of the parent.
- `ReconciliationMatcher`, `PlannedTransactionMatcher`, `BnplReceiptLinker`, `RedbarkTransactionMatcher` and the `includeRejected` path of `TransferLinker::candidatesFor` never offer a split child as a match candidate; the parent is the bank row.
- A split parent or child is never linked as a transfer, merged with a pending row or fee-folded (`TransferLinker::link()`/`suggest()`, `Transaction::scopeLinkable()`/`scopeNotSplit()`, `PendingMergeService`, `TransactionFeeFolder`).

### Writes

- Split and undo flip the parent's `excluded` flag in place. They do not create a new version. Deleting a current version restores the previous version, so a version-based flip would bring back an unsplit original.
- `createChild()` on a split parent re-points the children to the new version in the same DB transaction. Child versions keep their `split_parent_id`.
- Amount, date, account, type, currency and exchange-rate edits on a split parent or a split line, and turning one into a plan, are refused. The user undoes the split first.
- Editing or undoing a split moves the receipts on its lines to the parent before the lines are deleted, so no receipt is lost; if that would take the parent past `TransactionAttachment::MAX_PER_TRANSACTION` (10), the edit or undo is refused and nothing changes. Deleting the parent still deletes its receipts.
- A modal edit re-reads the row under the account lock and refuses if it was deleted or versioned since the form loaded it.
- Every write is one DB transaction. It takes the account lock first, which serialises split writes on that account; row locks then follow the version chain of the parent, then its children in id order. The balance history is rebuilt before it commits (#828).

### What cannot be split

`TransactionSplitter` refuses:

- transfers
- split children
- split parents (saving again replaces the lines instead)
- pending transactions
- excluded transactions
- rows that have category lines

Bulk edit skips split parents (`TransactionBulkEditor`). Bulk delete skips split children (`TransactionDeleter`). Children are deleted only with their parent.

## Consequences

- Two split mechanisms coexist: `TransactionSplit` category lines and child transactions. Each reader of splits has to know both. A user sees two ways to split one transaction until one is retired.
- The F4 acceptance criteria hold: lines that do not add up split nothing, and the excluded parent has the children.
- `TransactionModal` drives the split through `TransactionSplitter::save()`, `undo()`, `linesOf()` and `refusalFor()`.

### Not covered

- Grouping children under the parent in the transaction list: a later slice.
- Bank-feed amount change on a split parent: `SyncRedbarkFeedJob` updates a Redbark-source parent in place (no new version), so a changed bank amount or date leaves the children at their old amounts. Balances stay correct because only the parent moves them; spend and reports count the stale children until the user edits the split. A follow-up issue covers it.
- MariaDB lock ordering is not verified: the test suite runs on SQLite, which ignores FOR UPDATE.

## Reopen conditions

If `TransactionSplit` category lines are later retired in favour of child transactions, migrate the lines to children at that point.
