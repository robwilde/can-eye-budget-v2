# Transaction modal — type-by-date & plan lifecycle

Date: 2026-06-02
Status: planned (awaiting go-ahead to cut issues/branches)
Scope: 2 PRs against `develop`

## Goal

Make the transaction modal create/save the correct **type** based on the clicked
date, let the user add a transaction from any calendar day, give past planned
occurrences a clear "enter it" affordance, and stop "Convert to plan" from
destroying the source transaction — while back-filling a chosen category across
the whole matching recurring series.

## Confirmed decisions (2026-06-02)

1. **Add UX** — *any* calendar day click opens the add-transaction modal,
   pre-dated to that day. The day-detail panel still renders so existing
   pips (incl. planned occurrences) stay reachable for edit/reconcile.
2. **Past planned** — when a planned transaction is opened and its date `<= today`,
   the modal's primary button conditionally displays **"Enter expense/income/transfer"**
   (it is past → enter/reconcile it). It realizes the occurrence as a posted
   transaction; the recurring plan persists.
3. **Match scope (category back-fill)** — the **whole recurring series, all history**
   (same `MerchantSignature` + account + direction + amount cluster).
4. **PR split** — **two PRs**: (A) #1 + #2, (B) #3 + #4. #3 and #4 are coupled.

## Current architecture (grounded)

- `TransactionModal::openForAdd($date)` (`#[On('open-transaction-modal')]`) already
  sets `mode = isFuture() ? 'plan' : 'enter'`, `transactionType = 'expense'`.
  Mounted globally in `layouts/app/sidebar.blade.php`. Topbar + mobile FAB already
  dispatch `open-transaction-modal` with `now()`.
- Calendars only **select** a day: `CalendarView::selectDate()`,
  `Dashboard\PayCycleCalendar::selectDay()` → render the day-detail panel of pips.
  No `open-transaction-modal` dispatch from a day.
- `convertEnteredToPlanned()` creates a `PlannedTransaction` **and**
  `softDeleteWithAncestors($transaction)` — deletes the source + ancestors.
- Category propagation exists but only on **update** of an already-linked row:
  `PropagatePlannedTransactionCategory` / `PropagateTransactionCategory`
  (both gated on `planned_transaction_id`).
- Matching: `PlannedTransactionMatcher` (exact amount, account, direction within
  `ReconciliationMatcher::DATE_TOLERANCE_DAYS`, 45d lookback) and the analysis
  pipeline `IdentifyRecurringTransactionsStage` (groups by
  `MerchantSignature::for(desc)|direction|account_id`).
  `SuggestionApplier::applyRecurringTransaction()` is the reference pattern for #4:
  create plan → `whereIn(matched_ids)->update(planned_transaction_id + category_id)`.
- `DayActivityLoader` renders a `plan` pip per occurrence and **suppresses** it when
  a posted transaction is linked (`planned_transaction_id`) within tolerance — so
  "planned → posted" is purely a link, no state column.
- **Bug**: `<livewire:reconciliation-modal />` is only mounted on `calendar.blade.php`.
  Planned pips on the dashboard pay-cycle calendar dispatch `open-reconciliation-modal`
  with no listener → silent no-op.

## PR A — calendar add + planned-occurrence lifecycle  (#1 + #2)

### #1 — any day click opens the add modal
- `app/Livewire/CalendarView.php` & `app/Livewire/Dashboard/PayCycleCalendar.php`:
  add a method (e.g. `openDay(string $iso)`) that sets `selectedDate` (busts
  `selectedDay` cache, keeps the panel) **and** `$this->dispatch('open-transaction-modal', date: $iso)`.
- `resources/views/livewire/calendar-view.blade.php` &
  `resources/views/livewire/dashboard/pay-cycle-calendar.blade.php`:
  point the day `<button>` `wire:click` at the new method.
- No `TransactionModal` change: `openForAdd` already gives expense default and
  future → plan / today+past → enter.

### #2 — past planned shows "Enter …" + realize occurrence; dashboard modal mount
- `resources/views/dashboard.blade.php` (or app layout): mount
  `<livewire:reconciliation-modal />` so dashboard planned pips work (bug fix).
- `TransactionModal`:
  - Carry an optional `occurrenceDate` into the planned-edit entry so a specific
    recurring occurrence (not just `start_date`) governs the date. The
    reconciliation modal's `editPlanned()` must forward it.
  - Primary-button label (blade) becomes conditional: `editingPlannedTransactionId`
    + `date <= today` → "Enter expense/income/transfer".
  - New save path "realize planned occurrence": create a posted `Transaction`
    linked to the plan (`planned_transaction_id`) on the occurrence date; the
    recurring plan **persists**. (Distinct from `convertPlannedToEntered`, which
    hard-deletes the plan — keep that only for the explicit Plan→Enter mode toggle,
    or fold it in. To settle with tests: recurring vs `DontRepeat`.)
- Tests: day-click dispatch (CalendarView + PayCycleCalendar), conditional button
  label by date, realize-occurrence creates linked posted txn + plan survives,
  dashboard reconciliation modal reachable.

## PR B — convert-to-plan keeps source + series category back-fill  (#3 + #4)

### #3 — "Convert to plan" no longer mutates the source
- `TransactionModal::convertEnteredToPlanned()`: drop `softDeleteWithAncestors`.
  Create the plan from `$this->date`; **leave the source transaction intact**.
  Link the source (+ matching series) to the new plan so the calendar dedupes the
  shared date (`DayActivityLoader` suppresses the planned pip where a linked posted
  txn sits) — this also feeds #4.
- Button label may stay "Convert to planned …" (user's term) or become "Save as plan".

### #4 — category back-fills the whole recurring series
- New reusable service, e.g. `app/Services/PlannedSeriesMatcher.php` (or extend
  `PlannedTransactionMatcher`): given the source transaction / plan reference, return
  all `current()` transactions for the user with same `account_id` + `direction` +
  matching `MerchantSignature` + amount cluster — **all history** (decision #3).
  Signature is PHP-computed (like `IdentifyRecurringTransactionsStage`), so load
  candidates by account+direction and filter in PHP.
- On plan-create-from-transaction (and on planned `category_id` change), set
  `planned_transaction_id` on the matched series and **bulk-update `category_id` only**
  (mass `Builder::update()` fires no per-row events → no other field touched, no
  recursion). Mirrors `applyRecurringTransaction`.
- Reconcile with `PropagatePlannedTransactionCategory` so manual category edits use
  the same series definition.
- Tests: convert-to-plan keeps source (rewrite pinned tests at
  `TransactionModalTest` ~2304, 2377, 2728, 2765, 3035 to assert survival + link);
  category applied to all series members (incl. old ones) and nothing else changed;
  non-series transactions untouched.

## Test impact (existing, must update not delete)

`tests/Feature/Livewire/TransactionModalTest.php` cases asserting source soft-delete
on convert-to-plan must flip to assert the source survives and is linked:
- `converting entered expense to planned expense soft-deletes transaction and creates planned`
- `converting entered transfer to planned transfer soft-deletes both sides`
- `converting edited transaction to planned soft-deletes entire ancestor chain`
- `converting edited transfer to planned soft-deletes entire ancestor chain including pairs`
- `converting an entered transaction to planned keeps a planned occurrence on that date`

## Open implementation details (settle via TDD, not blocking the plan)

- Realize-occurrence for a `DontRepeat` plan: keep plan (link suppresses pip) vs
  deactivate. Default: keep + link.
- Which date governs realize when opened from a recurring pip: pass `occurrenceDate`.
- Whether convert-to-plan links only the source's occurrence or the full series at
  create time (decision #3 says full series for category; linking the full series is
  consistent and avoids double counts).
