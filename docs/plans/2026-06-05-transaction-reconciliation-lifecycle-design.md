# Transaction Reconciliation & Lifecycle Overhaul — Design

- **Date:** 2026-06-05
- **Status:** Approved (design phase)
- **Author:** brainstorming session (robwilde + assistant)

## Problem

The budgeting app has no single, consistent path for getting transactions into the
system, and no shared rule for deciding when an imported/entered transaction
*fulfils* a planned one. The result is visible on the calendar: a **planned pip and an
entered pip render on the same day** (observed Friday 5 June 2026) because the three
components that touch reconciliation each use a different rule.

### Current architecture (as-found)

State is **implicit / derived**, not stored:

- `Transaction.source` — `manual | csv | basiq | planned` (`app/Enums/TransactionSource.php`)
- `Transaction.status` — `posted | pending` — this is the *bank's* status, not a lifecycle
- `Transaction.planned_transaction_id` — non-null ⇒ reconciled to a plan
- Plans live in a separate `planned_transactions` table, projected forward by
  `PlannedTransaction::occurrencesBetween()`.

There is **no single ingress point**. Three independent paths:

- **CSV** → `App\Jobs\ImportCsvTransactionsJob` writes rows directly
  (`source=csv`, `status=posted`), then dispatches `RunTransactionAnalysisJob`.
- **Manual** → `App\Livewire\TransactionModal` (already correct: `createSingleTransaction`
  sets `source=manual,status=posted`; `createPlannedTransaction` supports net-new plans;
  `convertEnteredToPlanned` links the source via `planned_transaction_id`).
- **Basiq** → `App\Jobs\SyncTransactionsJob`.

Matching to plans happens **later, asynchronously**, in the analysis pipeline
(`MatchPlannedTransactionsStage` → `PlannedTransactionMatcher`).

### Root cause of the double-count

Three components disagree on the matching rule:

| Component | Amount rule | Date rule |
|---|---|---|
| `PlannedTransactionMatcher` (pipeline auto-link) | **exact** (`abs(amount) === plan.amount`) | ±3 days |
| `ReconciliationMatcher::findSuggestions` (UI) | **±10%** | ±3 days |
| `DayActivityLoader::load` (calendar dedup) | **none** (date only, over already-linked txns) | ±3 days |

The calendar suppresses a planned occurrence *only if* a transaction already carries a
matching `planned_transaction_id`. Linking is done by the exact-amount matcher, which
fails for variable bills, fails when it has not run yet, and fails when the plan was
created after the import — so both pips show.

### Other gaps

- **Balance:** `Account.balance` is a static stored int, never recomputed. Importing into
  an *existing* account has no balance input; the CSV balance column (`CsvColumnMapper::FIELD_BALANCE`)
  is parsed but never written to the account. User must edit balance manually.
- **Recurring autogen:** `IdentifyRecurringTransactionsStage` creates `RecurringTransaction`
  suggestions (shown in `AnalysisSuggestions`, applied to create plans). To be paused while
  the core flow is fixed; to be rewired into the rule system later.

## Decisions (from brainstorming)

1. **State stays derived** (no `lifecycle` column → no migration/backfill risk).
   Introduce a single `ReconciliationService` (ingestor) + domain events.
2. **Balance:** manual "current balance" field on import, **prefilled from the CSV's
   mapped balance column** (most-recent statement row) when available, editable.
3. **Recurring autogen:** **feature-flag off** (keep code), revisit to wire into rules.
4. **Net-new plans from scratch** are supported (already exist via the modal's plan mode;
   keep solid).

## Design

### A. Lifecycle, named and made consistent (no schema change)

| Lifecycle | Derivation | Created by |
|---|---|---|
| **Planned** | `planned_transactions` row with no linked transaction for that occurrence | manual plan, or promote-from-existing |
| **Entered** | `Transaction`, `planned_transaction_id IS NULL` | CSV / Basiq / manual (today or past) |
| **Reconciled** | `Transaction`, `planned_transaction_id` set | ingress match, or manual link |

**Invariant:** for any plan occurrence, the calendar shows *either* a planned pip *or* a
reconciled entered pip — never both.

### B. Single `ReconciliationPolicy`

Collapse the three divergent rules into one policy, used by ingress (to set the link) and
trusted by the calendar:

- same `account_id` + `direction`
- amount within **±10%** (`ReconciliationMatcher::AMOUNT_TOLERANCE`, covers variable bills)
- `post_date` within **±3 days** (`ReconciliationMatcher::DATE_TOLERANCE_DAYS`) of an occurrence
- one-to-one, greedy-nearest claim (so a match and the calendar agree on ownership)

`DayActivityLoader` keeps its occurrence-by-date claim over *already-linked* transactions
(unchanged) and trusts the link. The exact-amount rule in `PlannedTransactionMatcher` is
retired in favour of the shared policy.

- **Tradeoff:** ±10% can occasionally swallow a coincidental transaction near a plan's
  date+amount. This is the existing accepted UI tolerance and is required for variable
  bills. Manual link/unlink (`TransactionModal::convertPlannedToEntered`, `ReconciliationMatcher::unlink`)
  remains the escape hatch.

### C. The event system — `TransactionIngestor` + domain events

A single funnel for CSV, manual, and Basiq, using idiomatic Laravel events (the models
already fire `TransactionCategoryUpdated`):

```
ingest(candidate) → persist Transaction → ReconciliationPolicy.match(candidate)
   ├─ match → set planned_transaction_id → event TransactionReconciled
   └─ none  → event TransactionEntered
PlannedTransaction created → event PlannedTransactionCreated
```

New behaviour is added as a **listener**, never by editing ingress code — this is the
"extend without breaking others" property. Reconciliation runs **synchronously at ingress**
(deterministic; no race with calendar render). The existing `MatchPlannedTransactionsStage`
stays as an **idempotent backstop** for re-runs and for Basiq paths not yet migrated.

### D. CSV balance capture

Add a "current balance" field to the import wizard (`App\Livewire\ImportBank` + Blade) for
*existing* accounts. Prefill from the CSV's mapped balance column (most-recent row) when
present; editable. Write to `account.balance` on import confirm. The new-account path
already has this field.

### E. Disable recurring autogen (feature flag)

- `config('budget.recurring_detection')` ← `env('BUDGET_RECURRING_DETECTION', false)`.
- `IdentifyRecurringTransactionsStage::shouldRun()` returns false when disabled.
- `AnalysisSuggestions` hides `RecurringTransaction` suggestions when disabled.
- Code untouched, ready to rewire into the rule system later.

## Testing strategy

- **ReconciliationPolicy** unit tests: exact / within-tolerance / out-of-tolerance amount;
  in/out of date window; account & direction mismatch; one-to-one greedy claim (two
  occurrences, two candidates).
- **CSV ingest** feature test: importing a row that matches an active plan links it
  (reconciled) and the calendar renders a single pip for that day (regression for 5 June).
- **CSV ingest** feature test: a row with no matching plan stays Entered; a plan with no
  transaction stays Planned.
- **Balance** feature test: import sets `account.balance` from the field / CSV closing balance.
- **Feature flag** test: stage skipped and suggestions hidden when disabled; runs when enabled.
- Existing `MatchPlannedTransactionsStage` / `DayActivityLoader` tests updated to the shared policy.

## Ticket breakdown (modular PRs → `develop`)

**Epic:** Reconciliation & transaction lifecycle overhaul

1. **chore(config): feature-flag off recurring auto-detection** — `enhancement` + `backend`. No dependency.
2. **refactor: transaction-lifecycle domain events + unified `ReconciliationPolicy`** — `enhancement` + `backend`. Foundation; replaces the two divergent matchers. No behaviour change yet.
3. **fix: reconcile CSV imports at ingress (no planned + entered duplicate)** — `bug` + `backend`. Depends on #2. Fixes 5 June.
4. **feat: capture account balance during CSV import** — `enhancement` + `frontend` + `backend`. Independent.
5. **chore: route manual + Basiq ingress through `TransactionIngestor`** — `enhancement` + `backend`. Depends on #2.

Sequencing: **#1 → #2 → (#3, #5) → #4** (#4 any time).

## Out of scope (deferred)

- Rewiring recurring detection into the rule system (after core flow is solid).
- Basiq integration depth (CSV first).
- Storing an explicit `lifecycle` column (revisit only if derived state proves insufficient).
