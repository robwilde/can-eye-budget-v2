# Creating planned transactions from BNPL payment-schedule emails

**Epic:** #355
**Sub-tasks:** #356 #357 #358 #359 #360 #361 #362
**Related:** #363 (audit trail discovery)
**Date:** 2026-07-25
**Status:** Approved
**Source idea:** `context/todo/afterppay/afterpay-planed-payments.md`

## Problem

An Afterpay order confirmation email lists a complete future payment schedule — four
fortnightly instalments with dates and amounts — but nothing in the app consumes it. The
purchase is invisible to the forecast until the debits start landing weeks later.

Three distinct blockers:

1. **No ingestion trigger.** The existing Gmail subsystem (issues #340/#342/#351) is
   manual and per-transaction: `GmailService::searchForTransaction(Transaction $t)`
   requires an already-imported bank line to anchor a search — *"I have a line item, find
   me the receipt."* A schedule email arrives **before** any transaction exists and
   describes **future** payments. The data flow is inverted, and there is no scheduled
   scan, queued job, or webhook anywhere.
2. **No parser for this layout.** `ReceiptParser::afterpay` requires `Total amount paid $X`
   (`app/Support/Email/ReceiptParser.php:63`) or line items matching
   `Merchant Order #ref N of M $amt` (`:170`). An order confirmation has neither — it has
   `FIRST PAYMENT / $18.61 / DUE DATE: Fri, 07/08/2026` and
   `Afterpay order number: 953186001`. `parse()` returns `null` for it today.
3. **Bank debits are anonymous and combined.** Imported Afterpay lines say only
   `AFTERPAY`, and instalments from several orders falling due on the same day arrive as
   **one debit**. `ReconciliationPolicy` is ±10% amount, ±3 days, greedy 1:1
   (`AMOUNT_TOLERANCE = 0.10`, `DATE_TOLERANCE_DAYS = 3`), so a $55.83 debit cannot match
   an $18.61 plan — the band is $16.75–$20.47.

Blocker 3 is why this is one epic and not one issue. Shipping schedule import alone
leaves the combined debit unreconciled *and* every instalment pip unclaimed —
`DayActivityLoader::claimReconciledTransaction` only suppresses a pip when a *linked*
posting sits within ±3d — so the calendar would show $55.83 posted **and** $55.83 planned
on the same day, and `User::totalNeededUntilPayday` would over-state "Needed".

## Approach

Scheduled Gmail scan, not an inbound forwarding webhook.

Both transports generalise to Zip/Klarna/PayPal equally well, because provider-specific
logic lives in the parser, not the transport. The scan wins because the transport already
exists and is already paid for — `webklex/laravel-imap ^6.2`, the `gmail` account in
`config/imap.php`, app-password auth, `X-GM-RAW` search, and a `PROVIDER_SENDERS` map
already listing afterpay/paypal/klarna/zippay/zip.co/humm
(`app/Services/GmailService.php:46-55`) — and because forwarding requires a manual human
action per purchase, which defeats an automatic forecast. The scan also **backfills
history**; a webhook can only ever see mail sent after it is configured.

Ingestion sits behind a `ScheduleSource` seam so an inbound webhook can be added later as
a second driver feeding the same `parse → order → plan` path.

A parsed order becomes **one** `PlannedTransaction` — the identical shape a user gets by
creating a plan manually with a frequency and a cutoff date
(`TransactionModal::buildPlannedTransactionData`, `app/Livewire/TransactionModal.php:829-843`).
Not four rows. `Every2Weeks` anchored at the first due date with `until_date` at the last
projects exactly the four dates. The single-cent difference on the final instalment
($18.61 × 3 + $18.62) costs nothing on the single-plan path: ±10% tolerance matches an
$18.62 debit to an $18.61 plan, and `claimReconciledTransaction` then suppresses the pip.
§7 carries its own ±1c-per-instalment allowance so the combined-debit path agrees rather
than failing on every fourth instalment. No per-occurrence rows, no grouping column, no
adjustment logic, and **no migration on `planned_transactions` or `transactions`**.

Category and account are learned from order history rather than configured. The audit
record is the memory.

## Data model

Two new tables. Both follow the existing per-domain log convention (`BasiqRefreshLog`,
`PipelineRun`, `PipelineAuditEntry`, `BankImport`, `AnalysisSuggestion`); the app has no
auditing package and one is not being added.

### `bnpl_orders`

One row per parsed schedule email — current state plus full provenance.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascadeOnDelete |
| `provider` | string | `afterpay`, later `zip`/`klarna`/`paypal`/`humm` |
| `retailer` | string | `Petbarn` |
| `order_ref` | string | `953186001` |
| `total` | bigInteger | cents, MoneyCast |
| `instalment_amount` | bigInteger | cents; modal (most common) amount |
| `instalment_count` | unsignedTinyInteger | |
| `first_due_date` | date | plan `start_date` |
| `last_due_date` | date | plan `until_date` |
| `frequency` | string nullable | cast `RecurrenceFrequency`; derived from spacing; null when the cadence is unsupported |
| `account_id` | FK accounts nullable | nullOnDelete |
| `category_id` | FK categories nullable | nullOnDelete; null until approved |
| `card_last4` | string(4) nullable | provenance only, never used for resolution |
| `status` | string | `pending_review`, `approved`, `auto_approved`, `rejected` |
| `review_note` | string nullable | why an order needs review, e.g. `unsupported_cadence` |
| `planned_transaction_id` | FK planned_transactions nullable | nullOnDelete |
| `gmail_message_id` | string | |
| `subject` | string | |
| `email_date` | timestamp nullable | |
| `snippet` | text nullable | |
| `gmail_url` | string(2048) | `GmailService::deepLink` |
| `parsed_payload` | json nullable | raw `ScheduleParser` output |
| `reviewed_at` | timestamp nullable | |
| timestamps | | |

Indexes: `unique(user_id, gmail_message_id)` — the *scan* idempotency key, so a re-scan of
the same message is a no-op — and `unique(user_id, provider, order_ref)`, the *order*
idempotency key. A provider that resends a confirmation gives the same order a new message
id, and without the second constraint that copy would create a second
`PlannedTransaction` and double the forecast. Plus `(user_id, provider, retailer)` for the
category lookup and `(user_id, status)` for the review list.

`card_last4` is recorded but deliberately unused: the sample email names `**8357` while
the account table holds `3056`, `4373`, and one `NULL`, so last4 matching resolves nothing
today.

### `bnpl_order_events`

The audit trail.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bnpl_order_id` | FK bnpl_orders | cascadeOnDelete |
| `event` | string | see below |
| `payload` | json nullable | |
| `actor` | string nullable | `system` or user id |
| timestamps | | standard `timestamps()`; rows are written once and never updated |

Events: `email_pulled`, `plan_created`, `review_requested`, `approved`, `auto_approved`,
`category_set`, `rejected`.

Shape follows `pipeline_audit_entries`
(`database/migrations/2026_04_12_120004_create_pipeline_audit_entries_table.php`), the
closest existing precedent; the only deviation is naming the columns for this domain. That
table uses plain `timestamps()`, so this one does too, rather than carrying a
`$timestamps = false` model plus a hand-rolled `useCurrent()` column to save one column.

### Memory

Both derived from `bnpl_orders`; no preference table, no settings page.

| What | Key | Rule |
|---|---|---|
| Category | `(user_id, provider, retailer)` | non-null `category_id`, highest `reviewed_at` |
| Account | `(user_id, provider)` | approved order with the highest `reviewed_at`, else `users.primary_account_id` |

`reviewed_at` is set on **every** transition out of `pending_review`, auto-approvals
included, so it means "when this order's category was last decided". Ordering on it rather
than on `created_at` or `email_date` is what makes the backfill (§6) work: twelve months of
historical orders are created in a single scan, so creation order is arbitrary, and an
order categorised today must beat one whose email is newer but was categorised earlier.
Latest decision wins, so re-categorising one Petbarn order redirects every future one.

## Changes

### 1. Feature flag

`config/budget.php`: `'bnpl_email_import' => env('BUDGET_BNPL_EMAIL_IMPORT', false)`,
mirroring `recurring_detection` (`config/budget.php:20`), plus a `.env.example` entry.
Read via `config('budget.bnpl_email_import')`. Stays `false` until sub-tasks 1–7 land, so
the calendar never double-counts.

### 2. `ScheduleParser`

New `app/Support/Email/ScheduleParser.php`, a sibling to `ReceiptParser` rather than an
extension of it. `ReceiptParser` returns a *receipt* — a payment already taken
(`total`, `items`, `loanReference`). A schedule is a different shape, and one strategy
class per provider is the extension point for Zip/Klarna/PayPal.

Returns a `ParsedSchedule` DTO (`spatie/laravel-data`, already installed):
`provider`, `retailer`, `orderRef`, `total`, `cardLast4`, `instalments: list<{date, amount}>`.

Afterpay strategy extracts `Retailer: Petbarn`, `Afterpay order number: 953186001`,
`Total $74.45`, the four `$18.61` / `DUE DATE: Fri, 07/08/2026` blocks, and
`VISA card ending in **8357`. Body flattening reuses `ReceiptParser::flatten` — it is
already `public static` and shared with `GmailService`.

Two guards:

- **Weekday cross-check.** Parse `Fri, 07/08/2026` as DD/MM/YYYY and assert the weekday
  matches. As MM/DD that date is a Wednesday, so the weekday pins the format rather than
  assuming a locale. Mismatch → reject the email, log, no order row.
- **Cadence derivation.** Map the spacing between instalment dates to a
  `RecurrenceFrequency` case (14 days → `Every2Weeks`). If the dates are not evenly spaced
  at a supported cadence, leave `frequency` null; §4 then routes the order to
  `pending_review` with `review_note = 'unsupported_cadence'` and no plan. Never invent a
  plan that misrepresents the schedule.

`instalment_amount` is the mode of the parsed amounts. When a provider's email gives only
a total and a count, fall back to `intdiv(total, n)` with the remainder on the last — which
reproduces $18.61 × 3 + $18.62 from $74.45 anyway, so both paths agree.

### 3. Scan command and transport seam

- `RawEmail`: a `spatie/laravel-data` DTO alongside `ParsedSchedule` carrying what the
  parser and the order row both need — `messageId`, `subject`, `from`, `date`, `textBody`,
  `htmlBody`. It is new; nothing equivalent exists, as `EmailSearchResult` is the *result*
  of a per-transaction search and carries a rendered snippet rather than raw bodies.
- `ScheduleSource` contract: `fetch(CarbonImmutable $since): iterable<RawEmail>`.
- `GmailScheduleSource` implements it over the existing `webklex` client, reusing
  `GmailService`'s folder resolution (`['[Gmail]/All Mail', 'INBOX']`) and `X-GM-RAW`
  query style. Query shape: `from:afterpay.com "Afterpay order" newer_than:2d`.
- `app:scan-bnpl-emails` command in `app/Console/Commands/`, following
  `RefreshAllConnectionsCommand`, with `--since=` (§6) and `--user=` options.
- **Owning user.** The IMAP credential is one global mailbox (`config/imap.php`;
  `GmailService::isConfigured()` reads global config), so a scan has no per-transaction
  anchor to derive an owner from the way `searchForTransaction` does. `--user=` names it
  explicitly; omitted, the command resolves the single `users` row and **fails with a clear
  error when there is more than one**. Never guess an owner for financial records.
- Registered in `bootstrap/app.php` `withSchedule` alongside the existing entries
  (`bootstrap/app.php:19-26`):
  `$schedule->command('app:scan-bnpl-emails')->dailyAt('04:00')->timezone('Australia/Sydney')->withoutOverlapping();`
- No-ops when the flag is off or `GmailService::isConfigured()` is false.

Idempotency is the `unique(user_id, gmail_message_id)` constraint, so an overlapping
`newer_than` window costs nothing.

### 4. Order to plan

A `BnplOrderImporter` service:

1. `firstOrCreate` the `bnpl_orders` row on `(user_id, gmail_message_id)`; return early if
   it existed, and equally when `(user_id, provider, order_ref)` already exists — a resent
   confirmation is the same order, not a second one. Record `email_pulled`.
2. Resolve account from provider memory, else `primary_account_id`.
3. Resolve category from `(provider, retailer)` memory.
4. Determine whether the schedule is still live (`last_due_date >= today`). A settled
   order is audit history and category seed only — no plan is ever created for it.
5. Category found → `status = auto_approved`, `reviewed_at = now()`, record
   `auto_approved`; additionally create the plan and record `plan_created` when the
   schedule is live. Category not found, or `frequency` null → `status = pending_review`,
   `reviewed_at` stays null, no plan yet, record `review_requested`.
6. Plan payload matches `buildPlannedTransactionData` exactly:
   `description = "Afterpay - Petbarn"` (provider name + retailer), `amount =
   instalment_amount`, `direction = Debit`, `start_date = first_due_date`,
   `until_date = last_due_date`, `frequency`, `is_active = true`.

Approving from the review UI runs steps 4–6 with the chosen category, sets `reviewed_at`,
and records `category_set` + `approved`.

### 5. Review UI

New `app/Livewire/BnplOrderReview.php` + view, mounted on the existing `/rules` page
(`resources/views/rules.blade.php`) beside `<livewire:recurring-transaction-review />`,
which is the established review surface. No new route.

Lists `pending_review` orders with retailer, total, schedule, and a category picker.
Approve creates the plan for a live schedule and records the category for a settled one;
reject sets `status = rejected` and records `rejected`. Settled orders are flagged in the
list so it is clear they seed categories without adding to the forecast.

It also lists orders **auto-approved within the last 30 days**, read-only except for the
category picker. Without that the memory is write-once in practice: an order inheriting the
wrong category surfaces nowhere, and its plan is reachable only through
`PlannedTransactionManager`, which knows nothing about `bnpl_orders`. Changing the category
here updates the order, its plan, and `reviewed_at`, and records `category_set` — the
mechanism §Memory's "re-categorising one Petbarn order redirects every future one" already
assumes exists.

Badge is the `pending_review` count for the current user, rendered on the Rules item in the
sidebar nav. In-app only — no email notification. The component and its badge render
nothing when `budget.bnpl_email_import` is false.

### 6. Backfill

`app:scan-bnpl-emails --since=12months`, run once. Creates order rows for historical
Afterpay confirmations. Because no retailer memory exists yet, these land in
`pending_review` like any other unknown retailer — which is the point: the user assigns a
category per retailer in one sitting, seeding the memory so everything afterwards is
automatic.

Most backfilled orders are settled, so approving them records the category without
creating a plan (step 4). An order still mid-schedule does get a plan; its already-paid
instalments are claimed by the existing 45-day lookback backstop
(`PlannedTransactionMatcher`), and anything older than that window simply stays
unreconciled.

### 7. Combined-debit fan-out

A `BnplPaymentFanout` service, mirroring the existing `TransactionFeeFolder` N→1 fold,
inverted, invoked from a `TransactionEntered` listener. That event fires from
`TransactionIngestor::ingest()` on precisely the case that matters — a posting that matched
no plan — and it is a deliberate listener-free seam (`AGENTS.md:15`) with auto-discovery
already wiring `app/Listeners/`. The `TransactionFeeFolder` precedent supplies the *shape*,
not the trigger: it is called from `RuleActionExecutor`, not from ingestion.

For an unreconciled debit on a BNPL-linked account whose description matches a provider:
collect unclaimed instalment occurrences due within `DATE_TOLERANCE_DAYS`, capped at eight
candidates — beyond that leave the debit untouched rather than run an exponential search.

Match a subset whose plan amounts sum to the debit **within one cent per instalment**.
Exact equality is wrong here: `instalment_amount` is the mode, so the real 18/09 debit is
$18.62 + $18.61 + $18.61 = $55.84 while the plans sum to $55.83, and an exact rule would
refuse to fan out on the final instalment date of every order — the precise double-count
this sub-task exists to prevent. One cent per instalment is exactly the rounding artefact
§Approach knowingly discarded, and is far tighter than `AMOUNT_TOLERANCE`, which at 10% of
a combined debit would make ambiguity routine.

Prefer an exact-sum subset where one exists; otherwise the tolerant match must be unique.
Ambiguous or no solution → leave the debit unreconciled and untouched. Never guess.

On a match, `replicateWithOverrides()` a child per instalment (it already sets
`parent_transaction_id`, `app/Models/Transaction.php:248`), each carrying its own
`planned_transaction_id` and its order's `category_id`, then soft-delete the parent. The
residual between the debit and the plan sum lands wholly on the last child, so the children
sum to the parent **exactly** and no day total moves.

Soft-deleting the parent is required, not cosmetic: no query in `app/` filters
`whereNull('parent_transaction_id')`, so leaving both parent and children visible would
double every total. This is exactly how `TransactionFeeFolder` handles a folded fee — set
the link, then `delete()`.

## Verification

- `ScheduleParser`: the sample Afterpay order email yields retailer `Petbarn`, order ref
  `953186001`, total `7445`, four instalments `1861/1861/1861/1862`, card `8357`,
  frequency `Every2Weeks`. A `Total amount paid` payment-confirmation email yields `null`
  (still `ReceiptParser`'s job). A date whose weekday contradicts DD/MM is rejected.
  Unevenly spaced dates yield a schedule with no derived frequency.
- Idempotency: scanning the same message twice creates one `bnpl_orders` row and one
  `PlannedTransaction`. The same order arriving under a *different* message id likewise
  creates one row and one plan.
- Owning user: `--user=` binds the orders; with exactly one user the option may be omitted;
  with two users and no option the command fails and writes nothing.
- Unsupported cadence: an order with unevenly spaced dates persists with `frequency` null,
  `status = pending_review`, `review_note = 'unsupported_cadence'`, and no plan.
- Memory: first Petbarn order goes to `pending_review`; after approval with a category, a
  second Petbarn order is `auto_approved` with the same category and no review. Approving
  a later Petbarn order with a different category changes what the next one inherits. An
  order categorised today wins over one with a newer `email_date` categorised earlier.
- Correction: re-categorising an auto-approved order updates the order, its plan's
  category and `reviewed_at`, and the next order for that retailer inherits the new value.
- Account: with no prior order, resolves to `primary_account_id`; after one approved
  order, resolves to that order's account. `card_last4` never affects resolution.
- Plan shape: one row, `Every2Weeks`, `start_date` 07/08, `until_date` 18/09,
  `occurrencesBetween` returns exactly the four dates, description `Afterpay - Petbarn`.
- Settled orders: an order whose `last_due_date` is in the past creates no
  `PlannedTransaction` on either the auto or the reviewed path, but approving it still
  sets `category_id` and seeds the retailer memory for the next order.
- Reconciliation: an $18.62 debit on 18/09 links to the $18.61 plan and the calendar pip
  for that day is suppressed.
- Fan-out: three orders each owing $18.61 on the same date plus one $55.83 debit yields
  three child transactions with distinct `planned_transaction_id` and category values, a
  soft-deleted parent, unchanged day total, and no surviving planned pips. The same three
  orders on their final instalment date with a $55.84 debit fan out identically, the extra
  cent landing on the last child so the children still sum to $55.84. A $40.00 debit
  matching no subset leaves everything untouched, as does an ambiguous sum with two valid
  tolerant subsets, as does a day carrying more than eight candidate occurrences.
- Audit: a full auto-approved cycle writes `email_pulled` → `plan_created` →
  `auto_approved`; a reviewed cycle writes `email_pulled` → `review_requested` →
  `category_set` → `approved`.
- Flag off: the command no-ops, no rows are written, and the review component and its badge
  render nothing.

Tests are Pest. Email bodies go in `tests/Fixtures/emails/` as real fixture files rather
than the inline heredocs used by `ReceiptParserTest` — a full Afterpay order email is too
large to embed readably.

## Out of scope

- **Refunds and cancellations.** Afterpay emails on refund/cancellation are not parsed, so
  a cancelled order keeps forecasting. Accepted; the plan can be deleted manually.
- **Per-user mailboxes.** The IMAP account is a single global credential
  (`config/imap.php`). Effectively single-user, unchanged by this work.
- **Inbound forwarding webhook.** The `ScheduleSource` seam makes it additive later.
- **Providers other than Afterpay.** The strategy interface is the extension point; Zip,
  Klarna, Humm, and PayPal Pay-in-4 schedules are follow-on work.
- **App-wide audit trail.** Separate discovery EPIC; this spec covers BNPL only.
- **Correcting `accounts.account_last4`.** `BB - CC` stores `4373` while the same card
  appears as `x-8357` in PayPal receipts (issue #351). Unrelated data-quality issue.
