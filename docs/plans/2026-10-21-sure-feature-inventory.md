# Sure → Can-I-Budget feature inventory

Behaviour inventory of the user-facing features of Sure, a Rails personal-finance app, each mapped onto Can-I-Budget's existing models and conventions. Sure was read from a local checkout on 2026-10-21; Can-I-Budget claims were checked against this repository on the same date. Behaviour only: no Sure code, method bodies or file/line references.

## How to read this document

- **48 features, F1–F48**, grouped into eight areas. Every feature has five blocks, in this order: What the user can do · Data relationships · Edge cases · Acceptance criteria · Maps to Can-I-Budget.
- **Tenancy.** Sure scopes data by "family" (several users share accounts, budgets and rules). Can-I-Budget scopes by user. Inventory blocks say "family"; Maps blocks translate to the per-user model.
- **Preview-gated.** Features Sure only offers behind its per-user preview-features flag say so in their last "What the user can do" bullet.
- **Status** (Maps block):
  - **Covered**: the user can do essentially the same today. No feature currently qualifies.
  - **Partial**: part of it exists. *Existing* names which part; *Gap* names the rest.
  - **Missing**: nothing equivalent exists.
- **Build it like** names the existing model or component to extend, or the new model needed, and the conventions below by code. Proposed new class names are proposals, not existing code.

## Can-I-Budget conventions referenced as C1–C8

- **C1** Money is integer cents (bigInteger columns, `App\Casts\MoneyCast`); signed amount plus a direction.
- **C2** User scoping is by convention, not global scope: every user-owned table has `user_id` and every query filters on it explicitly; child tables are scoped through their parent. `Category` and `CategoryGroup` are global (no `user_id`).
- **C3** Main pages are `Route::view` wrappers around Livewire components inside the auth+verified group; settings pages use `Route::livewire('settings/…', 'pages::settings.*')` in the settings routes file, and the profile page sits in the `auth` group only. Every public Livewire method is audited automatically by the `AuditComponentCalls` hook unless marked `#[NotAudited]` (polling and dry-run only). Read-only state belongs in `#[Computed]` properties, which are not audited. Server-set state uses `#[Locked]`; writes re-scope to the user and re-check the persisted record.
- **C4** Write routes are named and pass through `AttributeRequestToUser`. Every queued job carries `AttributeJobToUser` with a user id and an audited flag. The guard test `UserActionInventoryTest` pins three lists: `#[NotAudited]` exemptions, named write routes (POST, PUT, PATCH, DELETE), and every class in `app/Jobs` with its audited flag. A new audited Livewire method needs no test change; a new write route or job does. GET routes are not in the inventory; they inherit `AttributeRequestToUser` from the web group.
- **C5** Models are `final` classes with `strict_types`, a `@property` docblock and `$fillable`; all but `TransferRule` have a `casts()` method, and all but `AuditEvent`, `TransferRule` and `UserCategoryBudgetTag` use `HasFactory`. The architecture tests enforce final services, string-backed enums in `app/Enums`, and final DTOs in `app/DTOs` extending `Spatie\LaravelData\Dto` (`ParsedTransactionDto` is the one DTO kept beside the CSV import services).
- **C6** Optional features sit behind `config/budget.php` flags, default off. Provider integrations keep their flags under `services.*` (for example `services.context_dev.enrichment_enabled`), also default off.
- **C7** Tests are Pest: Feature tests in `tests/Feature`, unit tests under `tests/Unit` (Casts, DTOs, Enums, Models, Services, Support), browser tests in `tests/Browser` (DDEV only). Clients built on the Http facade are faked with `Http::fake`; Context.dev is mocked through `ContextDevServiceContract` or a Guzzle `MockHandler`, because its SDK uses its own Guzzle client. The test database is in-memory SQLite.
- **C8** The runtime database is MariaDB 11.8 under DDEV while tests run on SQLite, so migrations and queries must work on both; no test runs against MariaDB itself. MariaDB's identifier limit means index names stay ≤ 64 characters, enforced by `MigrationIdentifierLengthTest` compiling the DDL with the MariaDB grammar. Related writes share one database transaction.

## Status summary

0 Covered · 39 Partial · 9 Missing

| # | Feature | Area | Status |
|---|---|---|---|
| F1 | Accounts | Ledger core | Partial |
| F2 | Balances, valuations, balance history and net worth | Ledger core | Partial |
| F3 | Foreign currency and exchange rates | Ledger core | Partial |
| F4 | Transactions | Ledger core | Partial |
| F5 | Transfers | Ledger core | Partial |
| F6 | Categories | Categories, tags and merchants | Partial |
| F7 | Tags | Categories, tags and merchants | Missing |
| F8 | Merchants | Categories, tags and merchants | Partial |
| F9 | Recurring series: types and data model | Recurring bills and income | Partial |
| F10 | Declaring bills and income, mark as recurring | Recurring bills and income | Partial |
| F11 | Frequency and cadence | Recurring bills and income | Partial |
| F12 | Occurrences and missed/past handling | Recurring bills and income | Partial |
| F13 | Recording payments (partial vs settle in full) | Recurring bills and income | Partial |
| F14 | Auto-matching payments and review queue | Recurring bills and income | Partial |
| F15 | Detection, confirm/dismiss, price changes, cleanup, recurring settings | Recurring bills and income | Partial |
| F16 | Series status lifecycle (suggested/active/paused/inactive/ended) | Recurring bills and income | Partial |
| F17 | Bills pages (overview, all bills, calendar) | Recurring bills and income | Partial |
| F18 | Bill audit | Recurring bills and income | Missing |
| F19 | Paycheck plan and pay periods | Recurring bills and income | Partial |
| F20 | Reminders, notices and calendar feed | Recurring bills and income | Partial |
| F21 | Monthly budget | Budgets, goals and insights | Partial |
| F22 | Goals | Budgets, goals and insights | Missing |
| F23 | Insights feed | Budgets, goals and insights | Partial |
| F24 | Rule definition, conditions and groups | Rules and automatic categorisation | Partial |
| F25 | Rule actions and field locks | Rules and automatic categorisation | Partial |
| F26 | Rule activation, preview, apply, ordering and auto-apply | Rules and automatic categorisation | Partial |
| F27 | Rule creation from a transaction (prompts, wizard) | Rules and automatic categorisation | Partial |
| F28 | Rule import/export | Rules and automatic categorisation | Missing |
| F29 | AI-assisted categorisation and merchant detection | Rules and automatic categorisation | Partial |
| F30 | Category/tag auto-assignment that is not a rule | Rules and automatic categorisation | Partial |
| F31 | Statement Vault | Statements, imports and provider sync | Partial |
| F32 | Bank-statement PDF import | Statements, imports and provider sync | Partial |
| F33 | Import wizard (CSV and others) | Statements, imports and provider sync | Partial |
| F34 | Provider sync (bank/crypto connections) | Statements, imports and provider sync | Partial |
| F35 | Family document store and file search | Statements, imports and provider sync | Missing |
| F36 | Data export | Statements, imports and provider sync | Missing |
| F37 | Dashboard widgets and period selector | Reporting and investments | Partial |
| F38 | Income statement, outflows and cash-flow Sankey | Reporting and investments | Partial |
| F39 | Reports page | Reporting and investments | Partial |
| F40 | Holdings, trades and securities | Reporting and investments | Missing |
| F41 | AI assistant chat | Platform, access and settings | Missing |
| F42 | MCP server and REST API | Platform, access and settings | Missing |
| F43 | Family, users, roles, sharing and tenancy | Platform, access and settings | Partial |
| F44 | Settings and preferences | Platform, access and settings | Partial |
| F45 | Notifications | Platform, access and settings | Partial |
| F46 | Onboarding | Platform, access and settings | Partial |
| F47 | Preview-feature flags | Platform, access and settings | Partial |
| F48 | Admin and self-hosting | Platform, access and settings | Partial |

## Build order and dependencies

Several features build on others. Building in table order would mean redoing later work, so settle these first:

1. **F43 tenancy decision.** Decided in `docs/decisions/0001-tenancy-stays-per-user.md` (#642): data stays per user, with no household. Every new user-owned model follows C2 as written.
2. **F44 preference store.** F3, F27, F37, F38, F39 and F47 all store per-user preferences in it.
3. **F9 series types and F12 stored occurrences.** F13 payments, F14 matching, F16 status lifecycle, F17 Bills pages, F19 paycheck plan and F20 reminders all read stored occurrences. F17 built earlier is an interim view only.
4. **F7 tags.** F4 tag filters, F24 tag conditions, F25 tag actions and F30 tag auto-assignment need it.
5. **F2 balance history.** F37 net-worth and balance widgets and F39 balance sections need it; F3 currency conversion applies to it once both exist.

The remaining features have no ordering constraint beyond the conventions above.

## Ledger core

### F1. Accounts

#### What the user can do
- Create a manual account of one of nine types.
  - Assets: cash or depository (checking, savings, health savings, deposit certificate, money market), investment (brokerage and tax-wrapper subtypes), crypto (wallet or exchange), property, vehicle, other asset.
  - Liabilities: credit card, loan (mortgage, student, auto, home equity, line of credit, business, other), other liability.
- Enter type-specific details.
  - Loans: rate type, rate, term, start date, down payment, insurance and rate-change schedule. These give an amortisation schedule and a payoff-projection tab.
  - Credit cards: available credit, minimum payment and annual fee.
- Edit an account's name, balance, currency, logo, institution name and website, and turn on "exclude from reports".
- Disable and re-enable an account. Delete it, which runs in the background. Unlink a bank-connected account, which keeps its data and makes it manual.
- Choose a default account for new transactions. Only active, manual cash or credit-card accounts qualify.
- See the account list: manual accounts grouped together, one card per bank or provider connection, and a "sync all" action.
- Open an account page:
  - a balance chart;
  - an activity feed with search, a status filter, an "uncategorized" filter and paging;
  - a statements tab;
  - for loans, a schedule tab.
- Share an account with other family members as full control, read/write (annotate only) or read-only.
  - The person it is shared with decides whether it counts in their own finances.
  - A family-level default can auto-share every new account.

#### Data relationships
- A family owns many accounts. Each account has an optional owner, who must be in the same family and defaults to the creator or the family admin.
- An account has exactly one type-detail record. It also has many entries (transactions, valuations, trades), daily balances, holdings, shares, recurring series and goal links. All of these are deleted with the account.
- Statements filed against a deleted account go back to the unmatched statement inbox. They are not deleted.
- Before an account is deleted, every transfer touching its transactions is dissolved. The surviving leg in the other account becomes an ordinary transaction again.
- A bank-connected account has one or more provider links. Unlinking removes the links, keeps every entry and detaches holdings from the provider.

#### Edge cases
- Name, balance and currency are required.
- Liabilities store debt as a positive number. A provider that reports a card or loan balance as negative is taken as the absolute value.
- A bank-connected account cannot be deleted from the UI. The user must unlink it first.
- Lifecycle: draft, active, disabled, pending deletion.
  - Draft and active are visible. Disabled accounts are kept for history only. Pending deletion is hidden everywhere.
  - If a background delete fails, an account that was already disabled keeps its original disable date. An active or draft account ends up disabled, with a disable date set at the time of the failure. If the delete cannot be queued, the account returns to its previous status.
- Clicking delete twice in quick succession rejects the second request.
- A loan start date cannot be in the future. The opening balance sits at the loan start date and the entered current balance sits at today, so the current figure survives the first sync.
- Excluded-from-reports accounts drop out of dashboards, reports and exports. Tax-advantaged accounts are left out of transaction-list totals: every investment subtype treated as tax-deferred, tax-exempt or tax-advantaged, crypto accounts with an explicit tax treatment, and health savings accounts.
- Transaction search shows only active and draft accounts by default.

#### Acceptance criteria
- Given a bank-connected account, when the user tries to delete it, then they are told to unlink first and nothing is deleted.
- Given an active account, when it is disabled, then it disappears from account pickers and the default transaction search. When it is re-enabled, it reappears and its disable date is cleared.
- Given a background delete that fails, when the job ends, then the account is disabled and can be deleted again.
- Given an account shared read/write, when the recipient bulk-edits its transactions, then only notes, category, merchant and tags change.
- Given a new loan with an original principal of 20,000 and a current balance of 15,000, when it is saved, then a 20,000 opening balance is dated at the loan start and a 15,000 balance is dated today.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Account, with these enums:
    - AccountClass: transaction, savings, credit card, loan, mortgage, investment, insurance, foreign, term deposit.
    - AccountGroup: day-to-day, long-term savings, hidden.
    - AccountStatus.
    - ImportSource: manual, csv, redbark.
  - Account also has a tracked flag, an optional credit limit, availableBalance and amountOwed; debt is a negative balance.
  - AccountManager can:
    - add and edit a tracked account;
    - add an untracked counterpart account via UntrackedAccountCreator;
    - convert untracked to tracked;
    - delete after showing the transaction count.
  - RedbarkAccountSetup and ImportBank create accounts from the bank feed and from CSV import.
  - User::totalOwed and User::totalAvailable count only active, tracked accounts.
  - User has a primary account (primary_account_id), chosen in onboarding's "Primary account" select. PlannedTransactionManager, RecurringTransactionReview and BnplAccountResolver use it as the default account; TransactionModal does not.
- **Gap:**
  - No disable/enable lifecycle. AccountStatus has four cases: active, available, inactive and closed. Only active is ever written, Account::active() treats available as active too, and every new account is active.
  - No exclude-from-reports flag, no loan or card details beyond a credit limit, no amortisation, no owner or sharing, and no unlink.
  - The primary account does not prefill TransactionModal, and there is no eligibility rule for it (active, manual, cash or card).
  - Delete is an immediate hard delete that cascades transactions, planned transactions, CSV imports, statement reconciliations and transfer rules, even for a bank-linked account.
- **Build it like:**
  - Extend Account and AccountManager:
    - use AccountStatus inactive for disable, with a disabled-at date;
    - add an exclude-from-reports boolean;
    - put loan and card details in a one-to-one detail model scoped through the account;
    - refuse delete while a RedbarkAccount is linked.
  - Prefill TransactionModal's account from the existing primary_account_id and add the eligibility rule there, instead of a new default-account column.
  - Conventions C2, C3, C5, C8. Sharing belongs with F43.

### F2. Balances, valuations, balance history and net worth

#### What the user can do
- Set an opening balance and its date when creating an account, and change it later.
- Add a reconciliation by picking a date and a total balance. A confirmation step shows cash and total before and after. A reconciliation's amount, date or notes can be edited, or it can be deleted.
- Set the current balance on a manual account. The app either adjusts the opening balance or adds a reconciliation dated today (see Edge cases).
- View a per-account balance chart over a chosen period.
  - Views: balance, cash, holdings and gains.
  - The trend counts as favourable when it rises for assets and falls for liabilities. The account list shows a sparkline.
- See family net worth (assets minus liabilities, in the family currency) with a net-worth chart for the selected period.
- See a balance sheet grouped by asset or liability and account type, sorted by the user's account-order preference (name or balance, ascending or descending).
- See a loan payoff chart with a projection.

#### Data relationships
- A valuation is an entry of kind opening balance, current balance or reconciliation. It records an absolute balance and is never income or expense.
- An account has at most one valuation per date and at most one opening balance.
- An account has one derived daily balance row per date. Each row holds start and end cash, start and end non-cash, inflows, outflows, market movement and adjustments. Rows are rebuilt from entries.
- Net worth reads every account the viewer can access and has chosen to count in their finances.

#### Edge cases
- An opening balance must be dated strictly before the oldest other entry; otherwise the error is "Opening balance date must be before the oldest entry date".
  - Without a date, it defaults to the earlier of two years ago and the day before the oldest entry.
  - No entry can be dated 30 or more years back.
- Reconciling a date that already has a valuation updates that valuation and does not add a second one.
- Setting the current balance on a manual cash or credit-card account with no reconciliations shifts the opening balance by the difference. On any other manual account it adds or updates a reconciliation dated today.
- Bank-connected accounts work backwards from the latest provider balance.
  - A newer provider balance turns the previous one into a reconciliation.
  - A same-day restatement updates in place.
  - An older balance is stored as history and does not change the current balance.
- An investment account is reconciled by its total. Cash is the total minus the value of current holdings.
- A reconciliation resets that day's end balance. Edits to name, notes, category or merchant do not trigger a balance rebuild.
- A disabled account counts in net-worth history up to the day before it was disabled, then drops out.
- When an account share is revoked, the account drops out of that member's balance sheet.

#### Acceptance criteria
- Given a cash account with opening balance 1,000, no reconciliations and transactions netting −200, when the user sets the current balance to 900, then the opening balance becomes 1,100 and no reconciliation is added.
- Given an account whose oldest transaction is on 1 March, when the user sets an opening balance dated 1 March, then it is rejected with the error above.
- Given a 500 reconciliation on 1 June, when the user reconciles 1 June at 600, then the account has one valuation on 1 June and it is 600.
- Given an investment account with holdings worth 4,000, when the user reconciles a total of 5,000, then cash is 1,000.
- Given assets of 3,000 and a credit card owing 1,000, when the dashboard loads, then net worth is 2,000.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Account has one stored balance with a balance source and an updated-at timestamp. It is written by AccountManager (manual), ImportBank (CSV) and SyncRedbarkFeedJob (bank feed), and recording a transaction does not change it.
  - Account::reconcileManualBalance, on untracked accounts through AccountManager, stores the last reconcile date and difference ("Reconciled … Difference").
  - StatementReconciliation and StatementReconciler record each month's statement totals and closing balance.
  - The Dashboard shows User::totalAvailable and User::totalOwed.
  - MonthlyProjectionService, BalanceProjection and the Dashboard MonthlyProjection component draw a 12-month forward projection for the primary account from planned transactions.
- **Gap:**
  - No opening-balance or reconciliation entries, so reconciliation keeps only the latest date and difference.
  - No daily balance history, no per-account balance chart, no net-worth figure or series and no balance sheet. AccountClass::isAsset exists but nothing calls it.
- **Build it like:**
  - A new AccountValuation model (scoped through Account), with a kind enum and a unique account-plus-date index.
  - A derived per-account daily balance table, rebuilt by a service beside Services/Projection. Every write that moves a balance or the transaction history triggers a rebuild: TransactionIngestor, TransactionModal writes (new versions, transfers and deletes), TransferLinker (including untracked mirrors), TransactionFeeFolder, ExpireRedbarkHoldsCommand hold removal, the SyncRedbarkFeedJob balance update, and balance writes by AccountManager and ImportBank. A valuation write rebuilds it inside the same DB transaction.
  - Net worth is the signed sum of the balances of active accounts, since debt is already stored negative. AccountClass::isAsset is used only for balance-sheet grouping. Align Account::amountOwed and User::totalOwed with AccountClass::isAsset so there is one liability definition (Mortgage counts as debt).
  - Conventions C1, C2, C5, C7, C8.

### F3. Foreign currency and exchange rates

#### What the user can do
- Give each account its own currency. The family has a base currency used for every total and report.
  - Admins can change the base currency. Stored amounts keep their own currencies; only reporting changes.
  - The family can limit which currencies the pickers offer. The base currency is always included.
- Enter a transaction in a currency other than the account's, with an optional exchange rate for that transaction. The form shows the current rate for that currency, account and date, or says the currencies are the same.
- Create a cross-currency transfer with an optional custom rate. The incoming amount is the outgoing amount converted.
- See transaction-list totals, income statement and balance sheet in the family's base currency.

#### Data relationships
- Each account, entry, daily balance and holding carries its own currency.
- An exchange rate is one row per currency pair per date. Bulk rate imports also store the inverse.
- Rates come from a configurable market-data provider and are cached. Without a provider, no new rates are added.

#### Edge cases
- A rate must be a finite number above zero. A zero or negative rate is rejected.
- Lookup order: the exact date, then the most recent cached rate from up to 5 days earlier, then the provider.
- Where no rate can be found, totals treat the rate as 1.
- Transaction-list totals convert at each entry's date. The balance sheet converts each account balance at today's rate.
- When auto-matching transfers between two currencies that are both not the base currency, the rate is derived through the base currency. If a rate is missing, that pair is not suggested.
- An account in more than one currency always rebuilds its whole balance history. A partial rebuild is never used.

#### Acceptance criteria
- Given a base currency of USD and a EUR account holding 1,000, when today's EUR→USD rate is 1.10, then the balance sheet shows 1,100 USD.
- Given no cached rate for a Saturday and a cached Friday rate, when a Saturday conversion is needed, then the Friday rate is used without calling the provider.
- Given no rate is available, when a 100 EUR expense is totalled in USD, then it counts as 100.
- Given a cross-currency transfer of 100 USD with a custom rate of 0.90, when it is saved, then the EUR account receives 90.
- Given a per-transaction rate of 0, when the transaction is saved, then it is rejected.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Account has a three-letter currency (default AUD).
  - SyncRedbarkFeedJob and RedbarkAccountSetup copy the bank feed's currency, normalised by RedbarkCurrency, with AUD as the fallback.
  - AccountClass has a "foreign" account type.
- **Gap:**
  - AccountManager, ImportBank and UntrackedAccountCreator always create AUD accounts, and transactions carry no currency.
  - There are no exchange rates, no base currency, no conversion and no cross-currency transfers. User::totalAvailable and User::totalOwed add cents across accounts whatever their currency.
- **Build it like:**
  - A new ExchangeRate model as global reference data, like Category: a unique pair-plus-date index and a decimal rate.
  - A fetch service, tested with Http::fake.
  - A base currency stored in the F44 preference store (a column on User), and a currency on Transaction.
  - Convert in User::totalAvailable, User::totalOwed and Services/Reports, behind a budget-config flag.
  - Conventions C1, C2, C5, C6, C7, C8.

### F4. Transactions

#### What the user can do
- Add a transaction to an account: name, date, amount, an inflow/outflow toggle, currency and kind. Notes, category, merchant, tags, an excluded flag and an exchange rate (foreign currency only) are optional. Submitting the same new-transaction form twice creates one transaction.
- Duplicate a transaction into a pre-filled new form (name, amount, category, merchant, notes, tags). On an investment or crypto-exchange account, convert a transaction into a trade; the original becomes excluded.
- Edit a transaction. Every field the user changes is locked against provider syncs, and the row is flagged as user-modified until the user chooses "Unlock".
- Delete one transaction or bulk-delete a selection. Bulk-edit date, name, notes, category, merchant and tags; tags change only when explicitly included, and an explicit empty tag list clears them.
- Exclude a transaction. It stays visible but drops out of every total, budget and report.
- Attach receipts: images or PDF, up to 10 per transaction, 10 MB each.
- Split a transaction into child lines, each with its own name, amount, category, notes and excluded flag. Edit or undo the split later. The list can optionally group children under their parent.
- Handle pending (provider-flagged) transactions:
  - merge a pending row into its suggested posted row;
  - dismiss the suggestion;
  - pick a posted match by hand from the same account and currency, 10 candidates at a time.
- Filter the list:
  - free text on name or notes;
  - amount equal to, less than or greater than a value;
  - date range;
  - type (income, expense, transfer);
  - status (pending, confirmed);
  - accounts;
  - categories, including "Uncategorized" (a parent includes its children);
  - merchants, including "No merchant";
  - tags, including "Untagged";
  - automatic-categorisation provenance;
  - attachment presence;
  - active accounts only.
  
  Filters and page size persist for the session, and filter chips clear one at a time.
- See a summary bar for the filtered set: count, income and expenses, converted to the family currency at the entry-date rate. When the set holds only transfers, it shows inflow and outflow instead.
- See recurring items due in the next 10 days in the transaction list and on an Upcoming tab.

#### Data relationships
- A family's account has many entries. Each entry is exactly one of: transaction, balance valuation or trade. Deleting the account deletes its entries.
- A transaction has:
  - at most one category, merchant and transfer;
  - many tags (deleting a tag only removes the link);
  - up to 10 attachments, scoped to the family.
- A split parent has many child transactions, deleted with the parent. A child cannot be deleted on its own.
- Deleting a transaction deletes any transfer it belongs to, plus that transfer's fee transactions. The other leg survives as an ordinary transaction.
- A provider-imported transaction carries an external id, unique per account and provider. Every transaction is in one of three states:
  - uncleared;
  - cleared (acknowledged by the provider);
  - reconciled (matched by a statement; undoable).
- Every transaction has a kind: standard, funds movement, credit-card payment, loan payment, one-time, or investment contribution. Funds movements, credit-card payments and one-time items stay out of budget analytics. Loan payments and investment contributions count as expenses.

#### Edge cases
- Split lines must add up to the parent amount, or nothing is split. While split, the parent is excluded and cannot be un-excluded. Children share the parent's date, merchant and kind.
- These cannot be split: transfers, split children, existing split parents, pending transactions and excluded transactions.
- Bulk operations:
  - Bulk edit skips split parents; bulk delete skips split children.
  - Both touch only accounts the user can write to.
  - Category, merchant or tag ids from another family are silently dropped.
- Pending transactions stay out of budget, income-statement and balance-sheet figures. During a SimpleFIN sync, a pending row older than 8 days is excluded automatically.
- Duplicate pending rows, for SimpleFIN syncs:
  - If exactly one posted row has the same amount and currency and is dated 0–8 days after the pending row, the pending row is excluded automatically.
  - Otherwise, a single posted row up to 25% larger, within 3 days and with the same first three name words, is offered as a merge suggestion.
- A merge:
  - keeps the pending row's date and category and the posted row's name and merchant;
  - joins the notes and moves tags and attachments;
  - is refused if the result would exceed 10 attachments.
- On a matched transfer, only the outflow leg's category can be edited. The inflow leg shows it read-only.
- Filter and totals behaviour:
  - "Uncategorized" ignores funds movements and credit-card payments but still lists uncategorised loan payments and investment contributions.
  - Tax-advantaged accounts (every tax-deferred, tax-exempt or tax-advantaged investment subtype, crypto with an explicit tax treatment, and health savings accounts) are left out of list totals.
  - Choosing both Pending and Confirmed applies no status filter.

#### Acceptance criteria
- Given a 100 outflow, when the user saves splits of 60 and 30, then an error is shown and nothing is split. When they save 60 and 40, then the parent is excluded and has two children.
- Given a pending 50 "Corner Cafe Main" and a posted 60 "Corner Cafe Main St" two days later, when the user merges, then one transaction remains with amount 60 and the posted name, plus the pending date and category.
- Given the same new-transaction form is submitted twice, then exactly one transaction exists.
- Given 20 selected transactions that carry tags, when the user bulk-sets a category without the tag option, then every tag is unchanged. When they bulk-set an empty tag list, then all 20 lose their tags.
- Given a synced transaction whose category the user changed, when the next sync sends a different category, then the user's category stays until they choose "Unlock".
- Given parent category "Food" with child "Groceries", when the user filters on "Food", then transactions in both appear.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Transaction:
    - signed cents plus TransactionDirection;
    - TransactionStatus (posted, pending) and TransactionSource;
    - notes and soft deletes;
    - every edit creates a new version through createChild.
  - TransactionSplit: category, amount and notes lines.
  - TransactionModal: add, edit, copy and delete. New rows reject an amount of zero or less, and it never deletes an original bank-feed row.
  - TransactionList:
    - URL-bound filters for direction, planned, categorised, category source, suggested transfers, account, category, category group, and period or date range;
    - search over description, clean description and merchant name;
    - sorting and merchant-cluster mode;
    - a split panel: at least two lines, each greater than zero, summing to the total; transfers cannot be split;
    - bulk set-category, revert machine categories, and create a rule from the selection.
  - TransactionEmail, linked by TransactionEmailLinker, attaches Gmail receipts.
  - Pending holds: SyncRedbarkFeedJob attaches a settled row to its pending hold within 8 days, and ExpireRedbarkHoldsCommand voids orphaned holds.
  - TransactionIngestor is the ingest path for CSV, statement, feed and manual single entries. Manual transfer legs, conversions, untracked mirrors and some single-row creates bypass it.
  - CategorySource keeps a Manual category from being overwritten by rules. CleanDescriptionSource ranks Manual, Rule, Feed, Brand and Derived, so a feed or brand name never overwrites a Manual or Rule display name.
  - TransactionFeeFolder folds international fees into their purchase.
- **Gap:**
  - Missing: tags; file attachments; an excluded flag; per-transaction currency or exchange rate; transaction kinds.
  - Missing list and bulk features: bulk delete; bulk edit of date, name or notes; amount, status and merchant filters; totals for the filtered list (only per-merchant-cluster totals); server-side idempotency; pending-duplicate merge.
  - Splits are category lines, not child transactions.
  - Category and display-name (clean_description) provenance are protected; notes, merchant and other fields are not.
- **Build it like:**
  - New user-owned Tag and TransactionAttachment models, with pivot and index names of 64 characters or fewer (C5, C2, C8).
  - Transaction edits create a new version (createChild), which copies only row attributes, so tags and attachments must be carried over to the new version.
  - An excluded flag on Transaction, honoured by the countable scope and CategoryAttribution.
  - Bulk delete and bulk edit as audited TransactionList methods that re-scope the frozen selection to the user (C3). Bulk delete skips original bank-feed rows and reuses deleteTransaction's transfer-partner handling: a bank-feed partner is unlinked through TransferLinker and a manual partner is deleted. Audited Livewire methods need no UserActionInventoryTest change (C4).
  - Amounts in cents (C1), with Pest feature tests per action (C7).

### F5. Transfers

#### What the user can do
- Create a transfer by choosing source and destination accounts, date and amount. Optional extras:
  - an exchange rate, when the two accounts use different currencies;
  - a non-negative fee on the source side, the destination side, or both;
  - tags, which are applied to both legs.
- The legs are named "Transfer to …" / "Transfer from …", or "Payment to …" / "Payment from …" when the destination is a liability. The transfer is confirmed at once.
- Edit a transfer's notes, category, amount, fees and status. A confirmed transfer into an investment account can be switched between "investment contribution" and plain transfer.
- Match a transaction to its other leg by hand: pick a candidate within 30 days on an account the user can write to, or create the missing leg on a chosen account. The result is confirmed.
- Review auto-matched transfers. Confirm one, or reject it; a rejected pair is remembered and never suggested again.
- Unmatch a transfer. Both transactions remain as ordinary transactions, and the fee transactions are deleted.
- Mark a transfer as recurring:
  - needs write access to both accounts;
  - unavailable when the family has turned recurring transactions off;
  - refused if an identical recurring transfer exists.
- Auto-matching runs after every provider sync.

#### Data relationships
- A transfer links exactly one outflow and one inflow transaction in the same family, on different accounts. A transaction belongs to at most one transfer.
- A transfer owns up to two fee transactions, one per side, in a "Fees" category. They are deleted with the transfer.
- Deleting either leg, or its account, deletes the transfer. The surviving leg reverts to a standard transaction.
- A rejected pair is stored, so those two transactions are never auto-matched again.
- On confirm, the outflow leg's kind follows the destination:
  - loan → loan payment;
  - credit card or other liability → credit-card payment;
  - investment or crypto → investment contribution (funds movement when the source is also investment or crypto);
  - anything else → funds movement.
  
  The inflow leg is always a funds movement.

#### Edge cases
- Auto-match pairs an inflow and an outflow on two different active or draft accounts. Neither may be excluded or already in a transfer. In the same currency, the amounts must be exactly opposite and within 4 days.
- Cross-currency matching:
  - The converted amount must be within a tolerance: default 10%, configurable, capped at 50%.
  - The rate is direct, or derived through the family currency.
  - Automatic matching only happens between two provider-linked accounts, or along a direction the user has confirmed before. Manual matching has no such restriction.
- Exact-amount matches are taken before currency-converted guesses, and matching is greedy.
- Auto-match needs at least one family member who can write to both accounts. An account with no owner does not block it.
- A pending auto-match changes no kind or category until confirmed. Unmatching a pending match also leaves kinds untouched.
- A pending transfer's legs may be at most 4 days apart; a confirmed transfer's legs at most 30.
- Repeating a create with the same idempotency key on the same source account returns the first transfer. The same key with changed details is an error. A missing exchange rate shows "unavailable".
- Investment contributions:
  - Confirming an uncategorised investment-contribution outflow gives it the "Investment Contributions" category.
  - Switching to plain transfer removes that category but keeps a custom one, and locks the kind against syncs.

#### Acceptance criteria
- Given 500 out of account A and 500 into credit-card account B two days apart, when a sync runs, then a pending transfer exists and neither kind has changed. When the user confirms it, then the outflow becomes a credit-card payment and the inflow a funds movement.
- Given a pending suggestion, when the user rejects it, then it is removed and the next sync does not suggest it again.
- Given a confirmed transfer whose legs are 25 days apart, then it is valid. Given a pending one whose legs are 5 days apart, then it is invalid.
- Given a confirmed transfer, when its outflow transaction is deleted, then the transfer is gone and the inflow is a standard transaction.
- Given a new 1,000 transfer with a 10 source fee, then three transactions exist: a 1,000 outflow, a 1,000 inflow and a 10 expense in "Fees".

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - TransactionModal transfer type:
    - creates two manual legs paired with TransferLinkSource Manual, and can swap the accounts;
    - links a bank-feed row to a chosen opposite row or to an untracked (hidden) account;
    - confirms, rejects or unlinks a suggestion;
    - in plan mode, saves a recurring transfer as a PlannedTransaction with a destination account.
  - TransferLinker:
    - candidates: opposite direction, same absolute amount, different account, within WINDOW_DAYS = 3;
    - link, suggest, confirm, unlink, markNotTransfer, linkToHiddenAccount and releaseMirrors, all under row locks in one DB transaction;
    - an uncategorised leg inherits its partner's Transfer category.
  - TransferDetector:
    - first applies remembered TransferRules, then suggests mutual single-candidate pairs among bank-feed rows carrying TransferSignal ("transfer" in the description, or the Transfer category);
    - runs from TransferDetectionStage and the transfers:detect command.
  - TransferReviewQueue and TransferReview: confirm, not-a-transfer, and link to a hidden account through UntrackedAccountCreator.
  - TransferRule: remembered on confirm; TransferRuleList lists and deletes rules.
  - TransferLinkSource Unlinked remembers rejections.
  - The Transaction countable scope keeps tracked-to-tracked pairs out of totals.
- **Gap:**
  - Missing: transfer fees, cross-currency transfers and exchange rates, payment kinds by destination (credit card, loan, investment contribution), tags on transfers, and server-side idempotency.
  - Matching needs exact amounts within 3 days; there is no 30-day manual window.
  - Auto-detection covers bank-feed rows only.
  - For bank-feed rows, a missing leg can only be an untracked mirror. Manual and CSV rows already get the missing leg created on any chosen account.
  - Deleting a manual transfer leg deletes both legs; there is no surviving standard transaction. A leg whose partner is a bank-feed row is unlinked instead.
- **Build it like:**
  - Extend TransferLinker and the TransactionModal transfer form with optional fee amounts that create fee Transaction rows in the same DB transaction (C1, C8).
  - Add a transfer-kind enum, derived from the destination AccountClass (C5). It is a label only and leaves countable() and CategoryAttribution unchanged, so a payment into a tracked loan, mortgage or investment account still never counts.
  - Keep writes audited and re-scoped to the user (C3).
  - Add Pest feature tests next to the existing TransferReview tests (C7).

## Categories, tags and merchants

### F6. Categories

#### What the user can do
- See the family's categories listed by parent, with a marker for those in use. Create a category with a name, colour, icon and optional parent; the icon comes from a fixed list of about 150, and one is suggested from the name. Edit any category.
- Create a category from a transaction's category picker; it is assigned to that transaction straight away.
- Delete a category and choose a replacement category, or none.
- Merge several source categories into one target category.
- Load a translated set of 22 default categories, each with a colour and icon; a default whose name already exists is skipped. Or delete all categories.
- An admin can lock categories. While locked, creating a category from the form, the picker, the defaults, the assistant or the API is refused, and non-admins see a "locked" message. Import category mapping still creates categories. Guests cannot change categories.
- Pick from a "recently used" shortcut of up to 4 categories. Only categories the user picked by hand count; rules and imports do not.
- Filter transactions by category. Picking a parent also includes its subcategories, and a synthetic "Uncategorized" option exists.

#### Data relationships
- A family has many categories. A category has an optional parent in the same family, and a parent has many subcategories, one level deep only.
- A category has many transactions (the category is optional on each transaction) and many budget lines.
- Deleting a category with no replacement clears it from its transactions. Deleting a parent turns its subcategories into top-level categories, and their transactions keep them.
- Merging moves transactions and budget lines to the target, gives the source's subcategories the target as parent, and then deletes the source, all in one transaction.
- Uncategorized and Other Investments are synthetic and never stored. Investment Contributions is created on demand.

#### Edge cases
- Categories are one level deep. A subcategory cannot get children ("can't have more than 2 levels"), and a parent cannot become a subcategory.
- Subcategories take their parent's colour when saved.
- Names are unique per family, and the reserved filter value `__uncategorized__` is rejected as a name. Name, colour and icon are required, and the colour must be a 6-digit hex.
- Categories have no income or expense type. Direction comes from the transaction's sign.
- A merge is refused when the target is also a source, when a parent would merge into its own subcategory, or when a category with subcategories would merge into a subcategory.
- When the source and the target both have a budget line in the same budget, the budgeted amounts are added together.
- The "Uncategorized" bucket leaves out funds-movement and card-payment transactions. Loan payments and investment contributions still count as uncategorised.
- Deleting or reassigning a category refreshes cached reports.

#### Acceptance criteria
- Given subcategory "Coffee" under "Food", when the user makes Coffee the parent of "Beans", then validation fails and nothing changes.
- Given a category with 40 transactions, when it is deleted with no replacement, then those 40 transactions are Uncategorized.
- Given category A budgeted at 200 and category B budgeted at 100 in the same month, when B is merged into A, then A is budgeted at 300, B no longer exists and B's transactions are under A.
- Given locked categories, when a non-admin opens the new-category form, then they see the locked message and no category is created.
- Given parent "Food" with subcategory "Groceries", when the user filters transactions by Food, then Groceries transactions are included.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Category is a global tree of unlimited depth. It holds parent, group, icon, colour, is_hidden and budget_tag; a missing icon is taken from the parent.
  - CategoryGroup holds name, colour, icon and sort order. CategoryGroupProvisioner seeds 13 groups and can be re-run safely.
  - CategoryEditor can:
    - search, create with an optional parent, and rename;
    - re-parent, with a guard that stops a category moving under itself or a descendant;
    - set the group, and hide or unhide;
    - create and edit CategoryGroups: name, colour (validated hex) and icon;
    - delete after a confirm that shows how many of the user's transactions use the category.
  - TransactionList filters by exact category or by group (both include splits) and by categorised or uncategorised.
  - Deleting a category clears it from Transaction, TransactionSplit, Budget, PlannedTransaction, BnplOrder and Payee (suggested and confirmed category), and deletes its UserCategoryBudgetTag rows. Its children become top-level.
- **Gap:**
  - Categories are shared by all users, so any create, rename, re-parent or delete changes them for everyone.
  - Missing: replace-on-delete, merge (it exists only inside the taxonomy-remap migration), per-category colour and icon editing, a lock, the "recently used" shortcut, creating a category from the picker, and a filter where a parent includes its subcategories.
  - Rules that set the deleted category keep pointing at its id.
- **Build it like:**
  - Add merge and replace-on-delete actions to CategoryEditor, backed by a new final service. It would rewrite Transaction, TransactionSplit, Budget, PlannedTransaction, Payee, UserCategoryBudgetTag, BnplOrder, the category_id and previous_category_id keys in AnalysisSuggestion and BnplOrderEvent payloads, and the category references inside rules in one DB transaction, the way the taxonomy-remap migration's merge step does (C8).
  - Decide on per-user scoping before adding merge, replace-on-delete or a lock (C2). Categories are global, so a merge or replace-on-delete would otherwise rewrite every user's Transaction, Budget and PlannedTransaction rows, and the lock would have to be an app-wide setting (C6).
  - New public methods are audited (C3), with Pest feature tests (C7).

### F7. Tags

#### What the user can do
- Manage the family's tags: create (name, plus a colour from a 10-colour palette with one picked at random), edit, delete and delete all. Guests can only view.
- Delete a tag with a replacement, so every transaction carrying it moves to the replacement first.
- Attach several tags to a transaction from a multi-select, or toggle one tag from a row picker. Quick toggles in a row do not cancel each other out.
- Bulk-tag through bulk edit. Tags change only when the user explicitly asks, and an empty selection clears them.
- Tags on a transfer, whether set when it is created or edited later, apply to both legs.
- Filter transactions by one or more tags, or by "Untagged".

#### Data relationships
- A family has many tags. Tags and transactions are many-to-many.
- Deleting a tag removes its links to transactions; the transactions stay.
- Merging a pending transaction into its posted match moves the pending transaction's tags to the posted one. Duplicating a transaction copies its tags.

#### Edge cases
- Names are unique per family, and the reserved filter value `__untagged__` is rejected as a name. Colour is optional, but must be a 6-digit hex if given.
- A tag cannot be replaced by itself.
- Tagging a transaction by hand protects its tags from being overwritten by bank sync.
- Tags from another family are ignored when assigning. A bulk update that names only those leaves existing tags alone.
- A transaction matching several selected tags is listed and totalled once.

#### Acceptance criteria
- Given tag "Holiday" on 12 transactions and an existing tag "Travel", when Holiday is deleted with Travel as the replacement, then all 12 transactions carry Travel and Holiday no longer exists.
- Given a transaction tagged A, when a bulk edit changes only the category, then tag A remains. When a bulk edit sends an empty tag list, then the transaction has no tags.
- Given a new transfer created with tag "Savings", then both legs carry Savings.
- Given a transaction tagged A and B, when filtering by A and B, then it appears once and the totals count it once.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none. A grep of models, migrations, routes, Livewire, services and config found no tag model, table, relation or UI. BudgetTag and UserCategoryBudgetTag are needs/wants/savings labels on categories, not transaction tags.
- **Gap:** users cannot attach free-form labels to transactions, bulk-tag them or filter by tag.
- **Build it like:**
  - A new final Tag model (user_id, name, colour, unique per user and name) and a tag-to-transaction pivot scoped through Transaction (C2, C5). The migration must run on both SQLite and MariaDB, with index names of 64 characters or fewer (C8).
  - Attach tags in TransactionModal and in TransactionList bulk actions, and add a tag filter to TransactionList's shared filter query.
  - Transaction edits create a new version (createChild), and a pivot is not copied automatically, so tags must be carried over to the new version.
  - A management Livewire component behind Route::view (C3), and Pest tests (C7).

### F8. Merchants

#### What the user can do
- Two kinds of merchant:
  - Family merchants, created by the user: name, colour (random if blank or invalid) and optional website. A logo comes from the website when a logo service is configured.
  - Provider merchants, from bank feeds or AI: shared across families, no colour.
- A merchants page with separate searches for family and provider merchants, plus a "recently unlinked" list covering the last 30 days.
- Create, edit and delete family merchants, including creating one from the transaction form's merchant picker.
- Editing a provider merchant's name or website turns it into a family merchant for this family only.
- "Delete" on a provider merchant only removes it from this family's transactions and lists it as recently unlinked.
- Merge several merchants (of either kind) into one target.
- "Enhance" starts a background job that finds websites for provider merchants that lack one, using an LLM. The Enhance button shows only when an OpenAI provider is configured, and the page shows how many merchants could be enhanced.
- Set the merchant on a transaction or through bulk edit, and filter transactions by merchant, including "No merchant".

#### Data relationships
- A family has many family merchants. Provider merchants are global, with names unique per source. A transaction has at most one merchant.
- Deleting a family merchant clears it from its transactions and deletes the recurring series attached to it.
- A merge deletes the family-merchant sources. Provider-merchant sources are kept, but are detached from this family's transactions.
- Converting a provider merchant moves only this family's transactions to the new family merchant. If a family merchant with the same name already exists, it is reused.

#### Edge cases
- Family merchant names are unique per family. Creating a duplicate name is a validation error. Converting a provider merchant, auto-detection and import reuse an existing same-name merchant.
- Merging, converting or unlinking marks the affected transactions as edited by the user, so bank sync does not revert them.
- A second Enhance request while one is still running for the family is refused with an "already running" message.
- The recently unlinked list leaves out merchants that are still on some of the family's transactions.
- Other families keep the shared provider merchant unchanged.

#### Acceptance criteria
- Given a provider merchant used by families A and B, when family A edits its name, then A gets a family merchant, A's 30 transactions point to it, and B's transactions are unchanged.
- Given family merchant X on 10 transactions and provider merchant Y on 5, when both are merged into Z, then all 15 transactions point to Z, X is deleted, and Y still exists.
- Given a provider merchant on 8 transactions, when the user deletes it, then those 8 transactions have no merchant, and it shows under recently unlinked for 30 days.
- Given a new family merchant saved with an invalid colour, then a random palette colour is stored.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - There is no merchant entity. Transaction carries merchant_name and a stored merchant_key, taken from the merchant name, else a description set by a person or rule, else the raw description. Rows with none of these go into one "(Unknown)" bucket.
  - TransactionList can:
    - group uncategorised rows into merchant clusters (25 per page) and select a whole cluster to bulk-categorise;
    - search on merchant_name;
    - offer "Identify merchant", "update visible merchants" and veto.
  - MerchantBrand (per user and merchant_key) stores a Resolved, Unresolved or Vetoed status, a title, domain, logo and industry.
  - Brand lookups use ResolveMerchantBrandJob and EnrichMerchantBrandsJob through ContextDevService. They are gated by services.context_dev.enrichment_enabled, DescriptorGate and the ContextDevCreditBudget daily cap.
  - BrandNameWriter fills the display name from a resolved brand and takes it back on veto.
  - Payee, PayeeReview and PayeeConfirmer confirm a category per merchant key. Confirming creates or updates a rule and recategorises the merchant's debits that were not categorised by hand.
- **Gap:**
  - Missing: a merchant record the user can edit (colour, website, logo from website), a merchants page, rename, merge, delete and unlink, a merchant picker or "No merchant" filter per transaction, and a recently unlinked list.
  - Two spellings of one merchant stay separate keys and cannot be merged.
- **Build it like:**
  - A new final, user-scoped Merchant model (C2, C5), keyed to the existing merchant_key or reached through a nullable merchant link on Transaction that is cleared when the merchant is deleted. It would reuse MerchantBrand for logos and Payee for categorisation.
  - Merge records an alias from the source key to the target Merchant, consulted by resolveMerchantKey, instead of rewriting merchant_key. That column is recomputed on save whenever merchant_name, clean_description or description changes, and every createChild version is a fresh insert, so a rewritten key would revert on the next edit, fee fold or feed version. Payee and MerchantBrand rows move to the target in the same DB transaction; each is unique per user and merchant_key, so a merge must resolve clashes (C8).
  - A Livewire management page (C3) and Pest tests with Context.dev lookups mocked through ContextDevServiceContract or a Guzzle MockHandler (C7).

## Recurring bills and income

### F9. Recurring series: types and data model

#### What the user can do
- Keep every repeating money movement as one record type, a recurring series. It is typed bill, subscription, installment, income, transfer or other, and all types appear under Bills.
- Give a series a name or a merchant (one of the two is required), an amount and a currency. Optionally add an account, a category, a payment link, notes and an autopay flag.
- Tune each series:
  - reminder window, in days;
  - overdue grace, in days;
  - amount tolerance, default 7.5%;
  - early and late match window, default 2 and 7 days;
  - amount strategy: fixed, average or last paid.
- Set how a series ends: never, on a date, or after a number of payments (at most 600). An installment plan with a payment count shows "N paid of M", with every payment and the end visible from the start.
- Record renewal, trial-end and cancelled dates on a series.
- When editing a payment link, optionally copy it to the user's other writable bills from the same merchant or with the exact same name.
- Delete a series. A series the user declared is removed completely. A detected series is kept as "ended" so detection does not suggest it again.
- Use the family-wide "recurring transactions" switch. Turning it off stops detection and nightly generation, hides Bills from the navigation, sends every Bills, occurrence and payment page home, makes the calendar feed not-found and blocks marking a transfer pair as recurring. Existing series are kept.
- Bills pages and the bill editor are behind Sure's preview-features flag. The older Settings → Recurring list, pause and delete are not.

#### Data relationships
- A series belongs to one family.
  - It optionally belongs to one account; a transfer series also has one destination account.
  - It optionally has one merchant and one category.
  - Its account and category must belong to the same family.
- A series has many occurrences. Each occurrence has many payments, and each payment optionally links one transaction.
- A user sees a series only if its account and destination account are both reachable by that user, or are unset.
- Changing a series needs write access to its account. A read-only share can view a bill but cannot pay, edit, pause, delete, confirm or dismiss it.
- Deleting a hand-declared series also deletes its occurrences, payments, price changes and rejected-match records.
- Deleting the family, the account or the destination account deletes the series. Deleting a category clears the series' category. Deleting a transaction keeps the payment and its amount and only clears the link.

#### Edge cases
- A series with a destination account is always typed transfer. It is invalid if the source equals the destination or the accounts are in different families.
- Income is stored as a negative amount. Bills, subscriptions and installments are stored as positive amounts.
- A zero amount is refused, because such a bill could never close.
- An amount with more than 4 decimal places is refused ("Use at most 4 decimal places"), not rounded.
- A payment link without a scheme gets https added. Only http(s) links with a host are accepted, so script links are rejected.
- Duplicates are judged on account, merchant or name, amount and currency:
  - a second tier from the same biller at a different amount is allowed;
  - creating the same bill twice gives "already exists", including for bills with no account.
- Detected series are typed from name keywords, and detection never retypes a series after creation:
  - BNPL names become installments;
  - streaming and software names become subscriptions;
  - utility, rent, insurance and telecom names become bills;
  - with no name signal, a flat, identical charge of 150 or less on a credit card becomes a subscription;
  - subscriptions and installments default to autopay.
- Giving an installment a payment count forces "end after count" and creates the whole plan at once.

#### Acceptance criteria
- Given a series with a destination account, when it is saved, then its type is transfer.
- Given a hand-declared bill with 3 occurrences and 2 payments, when the user deletes it, then the series, occurrences and payments are all gone.
- Given a detected subscription, when the user deletes it, then it becomes ended and the next detection run does not suggest it again.
- Given a bill "Rent" of 100 on an account, when the user creates another "Rent" of 100 on that account, then "already exists" is shown; when the second one is 60, it is accepted.
- Given a member with read-only access to a bill's account, when they try to pay or edit that bill, then it is not found.
- Given an installment plan of 4 payments with 1 paid, when viewed, then it shows "1 paid of 4" and all 4 due dates.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - PlannedTransaction is owned by one user. It has:
    - an account and an optional transfer destination;
    - an optional category;
    - an amount in cents with a TransactionDirection;
    - a description, a start date, a RecurrenceFrequency and an optional until date;
    - the flags is_active and is_pay_cycle_income.
  - Deleting an account deletes its plans. Deleting a transfer plan's destination account only clears `transfer_to_account_id`, so the plan quietly becomes an ordinary plan and starts counting through countable(). Deleting a plan clears the plan link on its transactions.
  - BnplOrder links a BNPL instalment schedule to its PlannedTransaction.
  - PlannedTransactionManager on the rules page lists, edits, toggles and deletes plans per tracked account, with a matched count and the next occurrence. It refuses to touch the pay-cycle income plan ("Pay cycle income is managed elsewhere").
- **Gap:** Plans have no type (bill, subscription, installment, income), merchant, payment link, autopay, renewal or trial dates, per-plan tolerance or match window, or end-after-count. There are no stored occurrences or payments. Deleting a plan always hard-deletes it, and only its owner can see it.
- **Build it like:**
  - Extend PlannedTransaction with a plan-type enum plus columns for payment link, autopay and end count (C5, C1, C8).
  - Keep per-user ownership via user_id (C2).
  - Expose the new fields in PlannedTransactionManager and TransactionModal plan mode (C3).
  - Add stored occurrences and payments as described under F12 and F13.

### F10. Declaring bills and income, mark as recurring

#### What the user can do
- Open "Add bill" (defaults to monthly) or "Add income" (defaults to every two weeks).
  - Required fields: Name, Amount, First due date, Frequency.
  - Optional fields: account, currency, payment link, autopay, notes.
  - On save the series is active and hand-declared, its occurrences are generated, and the user lands on Bills.
- Start from a suggested pattern. A fresh dialog offers up to 8 detected but undeclared patterns:
  - only on reachable accounts, not already covered by a series, and at least 1.00;
  - bill patterns need 2 or more charges;
  - income patterns need 2 or more deposits in 90 days, with the largest source first.
- Use "Not seeing what you're looking for?" to search all reachable transactions by name, notes or merchant. It shows the 20 most recent and leaves out transfers and excluded rows. Rows already paying a bill show "Part of X".
- Start from a transaction: name, unsigned amount, account and next due day are prefilled. A negative transaction prefills income.
- Ask for AI smart-fill (needs AI consent and a configured provider). It proposes name, amount, frequency, day, category, type and autopay from up to 40 past charges, for the user to review. A per-bill "smart configure" suggests only the fields that contradict current settings.
- Mark a transaction as recurring. This creates a hand-declared series on that day of the month, with an amount range learned from 6 months of similar charges.
- Mark a transfer pair as recurring. This creates a transfer series with source and destination accounts, and needs write access to both.
- Ask the assistant to create or update a bill, subscription, installment or income, using exact account and category names. It cannot create transfers.
- The add dialogs are behind Sure's preview-features flag. Mark as recurring is not. Marking a transfer pair as recurring is refused while the family's recurring switch is off; marking a single transaction is not.

#### Data relationships
- A declared series belongs to the family and starts hand-declared and active. Its account must be writable by the user.
- Currency defaults to the account's currency, then to the family's. A currency picked on the form wins.
- The first due date sets both the day of month or weekday and the anchor of the series.

#### Edge cases
- The user types a positive amount for income, and it is stored as negative.
- An account the user cannot write to, or one that does not exist, gives an explicit account error rather than being dropped.
- Mark as recurring makes income from a negative transaction and a bill otherwise. Similar charges count only within twice the amount and ±2 days.
- Mark as recurring is disabled with "already exists" when a matching hand-declared series exists.
- The assistant accepts only the published frequency presets. A word like "yearly" is refused, not guessed.
- A failed smart-fill keeps the plain prefill.

#### Acceptance criteria
- Given the Add income dialog, when the user enters 2,000 and saves, then the series is stored as −2,000, typed income, every two weeks.
- Given Name, Amount 100, a first due date on the 15th and Monthly, when submitted, then an active bill exists with occurrences on the 15th.
- Given a transaction that already has a matching hand-declared series, when its detail view opens, then "mark recurring" is disabled.
- Given the family's recurring switch is off, when the user marks a transfer pair recurring, then it is refused with a message.
- Given an account shared read-only, when the user picks it and submits, then an account error is shown.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - TransactionModal in plan mode creates a PlannedTransaction for expense, income or transfer. It takes a start date, which must be after today ("Planned transactions start after today."), a RecurrenceFrequency, and an until of "always" or a date on or after the start. The amount must be greater than zero.
  - Switching a hand-entered transaction to plan mode keeps the row and links it to the new plan, unless it is a transfer.
  - Switching a bank-feed row to plan mode creates a plan from its account, direction, unsigned amount and clean description, starting at the first occurrence after today. The row itself is not changed.
  - RecurringTransactionReview runs a "Find recurring" scan per account. Accepting a suggestion, with or without a category, goes through SuggestionApplier::applyRecurringTransaction; suggestions can also be dismissed.
  - BnplOrderImporter creates instalment plans from BNPL e-mails, behind budget.bnpl_email_import.
  - PayCycleConfigurator creates the single pay-cycle income plan.
- **Gap:** There is no dedicated add-bill or add-income flow with merchant, payment link, autopay or currency. There is no picker over undeclared patterns inside an add flow, no transaction search and no AI smart-fill. The modal does not check for an existing matching plan before creating one.
- **Build it like:**
  - Extend TransactionModal plan mode, or add a Livewire component on a Route::view page (C3), with the F9 fields on PlannedTransaction (C5).
  - Add a duplicate check scoped to the user (C2).
  - Build the pattern picker from RecurringTransactionDetector output, filtered by RecurringSuggestionWriter's skip rules (noise, already covered by a plan, recently rejected).
  - Cover it with Pest Livewire tests (C7).

### F11. Frequency and cadence

#### What the user can do
- Pick monthly, weekly, biweekly, semimonthly, quarterly, semiannual or annual, or "every N weeks/months/years" with N up to 99.
- Choose "last day" as the day of month.
- Set a weekend adjustment: none, skip, move before or move after.
- Change the cadence or the end condition. Only future occurrences that are still empty are rebuilt.
- Behind Sure's preview-features flag.

#### Data relationships
- A series has one cadence and one anchor date. Any "every N" cadence with N above 1 needs an anchor.
- Each occurrence keeps its original scheduled date as its identity, even when a weekend adjustment moves its due date.

#### Edge cases
- A biweekly series without an anchor is invalid.
- A day-31 bill falls on the last day of February.
- A schedule the picker cannot express, such as "3rd Friday", reads back as Custom and is left untouched.
- Setting a cadence by hand pins the schedule. Later detection refreshes the variance band and dates, never the amount, and does not move the cadence.
- Cadence edits never touch occurrences that are paid, closed, partly paid or already due.
- Applying "15th" and "last day" in either order gives the same schedule.

#### Acceptance criteria
- Given a biweekly series with no anchor, when saved, then it is invalid.
- Given a monthly bill on day 31, when February is generated, then it is due on February's last day.
- Given a monthly bill with March paid and April to June empty, when the cadence changes to quarterly, then March is unchanged and only April onwards is rebuilt.
- Given a cadence set by hand, when detection runs, then the cadence is unchanged.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - RecurrenceFrequency has 18 values: don't repeat, everyday, every 2–6 days, every week, every 2/3/4 weeks, every month, every 3 months, every 6 months, every year, every workday, every weekend, and 2 on 2 off.
  - PlannedTransaction::occurrencesBetween and nextOccurrenceOnOrAfter step forward from start_date up to until_date.
  - RecurringTransactionDetector maps median gaps to weekly, every 2 or 3 weeks, monthly, 3-monthly, 6-monthly and yearly.
  - A BnplOrder with an unsupported cadence gets no plan and cannot be approved.
- **Gap:**
  - Can-I-Budget has no semimonthly, no open-ended "every N", no last day of month, no weekend adjustment and no pinning.
  - Each monthly step is taken from the previous date with no overflow, so a plan starting 31 January runs 28 February, 28 March, 28 April (pinned by PlannedTransactionTest).
  - Occurrences are computed on the fly, so a frequency edit also moves past dates.
- **Build it like:**
  - Add cases to RecurrenceFrequency and compute each date from start_date rather than from the previous date.
  - Add a weekend-rule enum (C5), with tests alongside the existing RecurrenceFrequencyTest (C7).
  - New columns must work on MariaDB and SQLite (C8).

### F12. Occurrences and missed/past handling

#### What the user can do
- See each occurrence as upcoming, due, overdue, partially paid or paid:
  - upcoming until the reminder window opens (default 3 days before the due date);
  - due from then until the grace period ends (default 3 days after);
  - overdue after that.
- Act on an occurrence: mark paid (settle the remainder), skip (close without payment), reopen (back to scheduled), snooze until a date, or mark missed.
- Set or clear an expected amount for one occurrence only.
- Choose how the expected amount is worked out:
  - by default, the series' current amount;
  - "average": the expected average;
  - "last paid": what the previous paid cycle actually cost, from real payments.
- Browse occurrences from the start of the current cycle to about 90 days ahead. Annual bills show through their next occurrence, and the calendar generates up to 13 months ahead on demand.
- Behind Sure's preview-features flag.

#### Data relationships
- An occurrence belongs to one series and to the family.
- Its stored status is scheduled, paid, skipped or missed. Due, overdue, upcoming and partially paid are worked out from dates and payment sums and never stored.
- An occurrence is unique per series and original date, so regenerating never duplicates it.
- Closing an occurrence freezes its expected amount. Reopening keeps that frozen amount as an explicit override.
- Occurrences are generated when a series is created or its schedule is edited, after a bank sync, by a nightly run for families with no bank connections, when Bills is opened and none exist yet, and while browsing the calendar.

#### Edge cases
- Nothing is ever marked missed automatically. Only the user sets missed. An open past occurrence stays open and becomes overdue.
- Rebuilding history deletes past occurrences before the current cycle that are still open and have no payments, instead of marking them missed, so no debt is invented.
- A declared bill begins at its anchor date, so it never shows debt from a previous cycle.
- Snoozing moves the effective due date, so an overdue occurrence snoozed into the future shows as upcoming. A snooze value that is not a date gives "That date could not be read".
- Open occurrences left on a paused or inactive series are never due or overdue. They show "Paused" in a Dormant section, not under Past Due.
- A price change applies from today:
  - occurrences already due with no stored amount are pinned to the old amount, and later edits cannot move that pin;
  - occurrences with payments keep their frozen amount;
  - editing a field other than the amount pins nothing.

#### Acceptance criteria
- Given a bill due 8 days ago with default settings, when viewed, then it is overdue.
- Given an overdue occurrence, when the user snoozes it to next week, then it shows as upcoming.
- Given a paused bill with an unpaid past occurrence, when viewed, then it shows "Paused" under Dormant and not as overdue.
- Given an open occurrence from 3 months ago with no payment, when history is rebuilt, then it is removed, not marked missed.
- Given a bill of 100 raised to 120 today, when saved, then occurrences already due expect 100 and future ones expect 120.
- Given months pass with no payments, then no occurrence becomes missed unless the user marked it.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Occurrences are computed, not stored. PlannedTransaction::occurrencesBetween returns none for an inactive plan and stops after 1000 steps.
  - A Transaction carrying planned_transaction_id claims the nearest occurrence within ReconciliationPolicy's ±3 days.
  - DayActivityLoader shows unclaimed planned items only from today on, so an unpaid past occurrence simply drops off the calendar.
  - Plan-vs-actual (MonthFlowLoader) counts every occurrence in the month's plan. The page says missed planned bills "stay in the plan and are simply absent from actual".
  - Opening today's occurrence from a calendar pip in TransactionModal records it as a linked Transaction (realizePlannedOccurrence).
- **Gap:**
  - With no stored occurrence there is no paid, skipped or missed state, no due or overdue state, no snooze, no per-occurrence amount and no frozen amount.
  - Editing a plan's amount or frequency, or toggling it inactive, rewrites past plan figures.
  - Editing a plan opened from a future occurrence moves its start date to that occurrence.
- **Build it like:**
  - Add a new final occurrence model owned by PlannedTransaction (C2, C5), unique on plan and original date, with an index name of at most 64 characters (C8).
  - Give it a status enum (scheduled, paid, skipped, missed) and an expected amount in cents (C1).
  - Generate occurrences in a queued job carrying AttributeJobToUser, added to the UserActionInventoryTest job list with its audited flag (C4).
  - Add audited Livewire actions on the calendar and in PlannedTransactionManager (C3), with Pest feature tests (C7).

### F13. Recording payments (partial vs settle in full)

#### What the user can do
- Attach an existing transaction to an occurrence, for its full usable amount or a custom amount.
- Record a payment with no transaction: an amount plus an optional paid-on date, which can be in the past.
- "Mark paid" adds a payment of exactly the remaining amount and closes the occurrence. If nothing remains, it just closes.
- Split one transaction across several occurrences or several bills.
- Remove a payment; whether the occurrence is open or closed is then worked out again.
- Pick from a candidate list:
  - transactions on the bill's account, or on any reachable account if the bill has none;
  - same sign and currency, and not transfers unless the series is a transfer;
  - by default dated from 40 days before the due date to 40 days after it or today, whichever is earlier;
  - ranked by the same scoring as auto-matching, with the match signals shown, at most 6;
  - rejected pairs excluded;
  - a fallback list sorted by closeness of amount, capped at 15;
  - a text search, which drops the date window.
- See the name aliases and the widened tolerance the bill has learned.
- Behind Sure's preview-features flag.

#### Data relationships
- An occurrence has many payments, and each payment optionally links one transaction.
- A transaction can pay many occurrences, but at most once per occurrence. The total paid from one transaction cannot exceed its amount.
- A payment's currency must equal the occurrence's currency. A foreign-currency transaction is converted at the rate on its own date.
- Recording a payment never creates a transaction. Deleting a transaction keeps the payment and its amount.

#### Edge cases
- Partial payments must add up exactly, with no tolerance:
  - 1,850 against 2,000 is partially paid with 150 remaining, and the occurrence stays open;
  - several payments accumulate and close the occurrence once their sum is within 1 cent of the expected amount.
- A single payment within the series' tolerance closes the occurrence as paid. The real amount is recorded and the estimate stays.
- Over-payment is allowed: a single payment above the expected amount is how a price rise is learned. The assistant's payment tool, however, refuses amounts above the remaining balance.
- An occurrence that closed automatically reopens when a payment is removed and it no longer qualifies. One the user closed with "mark paid" never reopens on its own.
- Paying more than a transaction's amount is refused with "That would allocate more than the transaction's amount". A partly used transaction is still offered for another occurrence.
- With no exchange rate the user must type the amount ("No exchange rate available; enter the amount explicitly").
- A malformed paid-on date is rejected, not saved as today.
- Learning:
  - attaching a transaction with a different name teaches the bill that name;
  - a single payment that settles the bill but sits outside tolerance widens the tolerance, up to 25%;
  - partial payments teach nothing.

#### Acceptance criteria
- Given rent of 2,000, when a payment of 500 is recorded, then the occurrence stays open, partially paid, with 1,500 remaining.
- Given that partially paid occurrence, when the user clicks "mark paid", then a payment of 1,500 is added and the occurrence closes as paid.
- Given a 100 transaction with 60 already paid to bill A, when the user puts 60 of it on bill B, then it is refused; when they put 40, it is accepted.
- Given an expected 20 and tolerance 7.5%, when a single payment of 21 is attached, then the occurrence closes as paid and history shows 21.
- Given an occurrence closed automatically by one payment, when that payment is removed, then it reopens.
- Given an invalid paid-on date, when submitted, then the payment is rejected.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - A Transaction pays at most one PlannedTransaction, through planned_transaction_id. The link is set:
    - at import, by TransactionIngestor with ReconciliationMatcher: same account and direction, amount within ±10%, post date within ±3 days, nearest unclaimed occurrence wins;
    - by PlannedTransactionMatcher and the app:match-planned-transactions command, with a 45-day lookback and ties left unmatched;
    - by the LinkToPlannedTransaction rule action;
    - by SuggestionApplier, when a recurring suggestion is accepted;
    - by TransactionModal, when an occurrence is realised (which creates the transaction).
  - BnplPaymentFanout splits one bank debit into child transactions, one per BNPL instalment plan. It does so only when exactly one set of 2–8 unclaimed instalments adds up to the debit, exactly or within a cent per instalment.
- **Gap:** There is no payment record separate from the transaction. So there is no partial payment or remainder, no "mark paid", no payment without a transaction, no over-payment handling, no per-occurrence closing and no learning. Outside BNPL, one transaction cannot pay several plans. Automatic matching (at ingest and in the backstop) ignores a transaction more than 10% off the plan; a LinkToPlannedTransaction rule or a realised occurrence links any amount.
- **Build it like:**
  - Add a new final payment model owned by the F12 occurrence (C2, C5), with an optional transaction link, an amount in cents and a paid-on date (C1).
  - Put the cap and close rules in a final service where related writes share one DB transaction with row locks, as TransferLinker does (C8).
  - Make the pay, mark-paid and unlink Livewire actions audited (C3), with Pest tests (C7).

### F14. Auto-matching payments and review queue

#### What the user can do
- Bank transactions are linked to open bill occurrences automatically after every sync and after every detection run, with no manual step.
- Review a queue of suggested matches on the Bills overview. It is ordered by confidence and shows the signals behind each match: name, amount, date and account.
- Confirm a suggestion, which turns it into a real payment on the occurrence.
- Reject a suggestion, which removes it and stops that transaction from ever being proposed for that bill again.
- A bill row with a pending suggestion offers "Review" instead of "Find".
- Behind Sure's preview-features flag.

#### Data relationships
- A suggestion is an unconfirmed payment linking one transaction to one occurrence. Confirming it makes it an ordinary payment.
- A rejection is a permanent record of a (series, transaction) pair. It is deleted together with a hand-declared series.
- Only open occurrences in the same family match. Occurrences are generated only for active series, but leftover open occurrences of a paused or inactive series stay matchable, so a late payment can still settle them.
- When a matched transaction is deleted, its payment stays with its amount and only the link to the transaction is cleared.

#### Edge cases
- Hard filters:
  - Same currency and sign.
  - Same account when the series is tied to one.
  - Same merchant, a known name or a learned alias.
  - Not a transfer leg, except that a recurring-transfer series matches the outflow of a transfer pair into its destination account.
- Score:
  - Identity: about 0.35–0.40.
  - Amount: 0.30 if exact; 0.25 falling to 0.15 within tolerance; outside tolerance the candidate is dropped.
  - Date: up to 0.20.
  - Account: 0.10.
- A match scoring 0.85 or more auto-links only if the transaction is posted and the match beats that transaction's next-best occurrence by at least 0.15. Otherwise it becomes a suggestion.
- A score from 0.60 to 0.85 creates a suggestion. Below 0.60 nothing happens.
- A pending transaction is never auto-linked; at most it is suggested.
- Income never produces suggestions: a deposit either matches at the top tier or the payday waits.
- The match window runs 2 days early to 7 days late by default, capped near half a cycle. Overdue occurrences stay matchable up to today.
- Within one run, each transaction and each occurrence is claimed at most once.
- If a provider replaces a pending transaction with a posted one and the link is lost, the payment is re-attached to the transaction with the same date and amount.

#### Acceptance criteria
- Given a monthly bill of 100 due on the 10th with default windows, when a posted 100 charge from the same merchant on the 12th syncs, then the occurrence closes as paid with no user action.
- Given a match scoring 0.70, when sync completes, then it appears in the review queue; when the user rejects it and sync runs again, then that transaction is never suggested for that bill again.
- Given a pending 100 charge that would otherwise score 0.90, when matching runs, then the occurrence stays open and the link is only a suggestion.
- Given two open 100 bills from the same merchant that score within 0.15 of each other for one 100 charge, when matching runs, then a suggestion is created instead of an auto-link.
- Given an income series of 2,000 and a 2,000 deposit scoring 0.70, when matching runs, then no suggestion is created and the payday stays open.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - TransactionIngestor links every newly ingested transaction to an active PlannedTransaction through ReconciliationMatcher and ReconciliationPolicy: same account and direction, amount within ±10%, post date within ±3 days, nearest unclaimed occurrence.
  - PlannedTransactionMatcher runs the same rule as a backstop over the last 45 days. It is called from MatchPlannedTransactionsStage and the `app:match-planned-transactions` command. It leaves a tie unmatched and skips transfers.
  - The LinkToPlannedTransaction rule action links by hand-written rule.
  - TransactionModal records today's planned occurrence, opened from a calendar pip, as a linked transaction.
- **Gap:**
  - There is no confidence score, no suggestion tier and no review queue: a match is either written silently or not made.
  - Rejections are not remembered, recurring transfers are never matched, and a link cannot be undone from the UI.
- **Build it like:**
  - Keep ReconciliationPolicy as the single rule source and add a score to it.
  - Surface mid-confidence matches as a new SuggestionType on AnalysisSuggestion, reviewed in RecurringTransactionReview. An AnalysisSuggestion needs a PipelineRun, so scoring runs only in MatchPlannedTransactionsStage; TransactionIngestor outside a pipeline run (such as manual entry) keeps link-or-nothing.
  - Store rejected pairs in a new final model keyed by user, plan and transaction.
  - Conventions:
    - C1, C2, C3, C5, C7.
    - C4: the new public actions are audited Livewire methods, so UserActionInventoryTest needs no change.
    - C8: unique index name of 64 characters or fewer; the link and the suggestion status are written in one transaction.

### F15. Detection, confirm/dismiss, price changes, cleanup, recurring settings

#### What the user can do
- Run detection on demand with "find my bills" on an empty Bills page or "Identify patterns" in Settings → Recurring.
  - A message reports how many new suggestions this run created.
  - A run started while another is in progress gets "already running".
- Detection also runs automatically after bank syncs. It runs 30 seconds after the last sync and waits until every provider sync has finished.
- Review detected series:
  - Bill suggestions appear in a strip on the Bills overview and in Settings.
  - Income suggestions appear only in Settings.
- Confirm a suggestion to make it an active bill. The first confirm rebuilds 6 months of history.
- Dismiss a suggestion so it is never suggested again.
- See recorded price changes on each bill, and a list of price changes in the last 12 months (capped at the 10 most recent) under the subscription filter.
- Press "Clean up stale" in Settings to retire series that have stopped.
- Turn recurring off for the whole family in Settings → Recurring. This stops detection and nightly occurrence generation, hides Bills, sends the Bills pages home, makes the calendar feed not-found and blocks marking a transfer pair as recurring. Existing series are kept.
- A first-visit prompt counts detected bills awaiting review. It drops to zero once the user has declared a bill, dismissed anything or recorded a payment.

#### Data relationships
- A detected pattern is one merchant or name, plus currency and account. It becomes a series with status suggested and no occurrences.
- Before creating anything, a pattern claims the nearest existing series within tolerance, whether manual, active or ended. Detection therefore never recreates a declared or dismissed bill.
- A series has many price changes (previous amount, new amount, effective date). They are deleted with a hand-declared series.
- The family has one recurring on/off switch. It governs detection, generation, the Bills pages, the calendar feed and marking a transfer pair as recurring.
- Inactive detected series untouched for 6 months are deleted with their occurrences. Hand-declared series are never deleted automatically.

#### Edge cases
- A pattern needs all of:
  - 3 or more charges in the last 3 months, the latest within 45 days.
  - Days of the month clustered within about 5 days.
  - Amounts within 7.5% of the cluster's running mean. A creeping price stays one series; different price tiers become separate series.
- Transfer legs and investment or crypto accounts are never scanned.
- Re-detection refreshes the variance band, the occurrence count and dates, and the due day unless the schedule is pinned. It never changes the amount or status; an amount changes only through price-change detection. A cadence the user set by hand is pinned.
- Price changes:
  - A price change needs two consecutive paid occurrences, each settled by a single payment, agreeing on a new amount.
  - One odd charge or partial payments never count.
  - Detected series take the new amount; manual series only record the change.
- Staleness is measured in the series' own cycles:
  - A series goes inactive when its last occurrence is older than two of its own cycles.
  - The floor is 2 months for detected series and 6 months for manual ones.
  - It is first checked against recent matching transactions.
- If the family lock is held, the first-confirm backfill is skipped but the confirm still succeeds. Confirming again never re-runs the backfill.
- The type is set once, from keywords, and never changed afterwards:
  - Buy-now-pay-later names become installment.
  - Streaming and software names become subscription.
  - Utility, insurance, rent and telecom names become bill.
  - A flat, modest card charge with no name signal becomes subscription.
- The Settings → Recurring list, pause/delete, "Identify patterns", "Clean up stale" and the switch are not preview-gated.

#### Acceptance criteria
- Given charges of 50 on the 5th of each of the last three months, when detection runs, then one suggested series appears with no occurrences.
- Given that suggestion was dismissed, when detection runs again, then it is not suggested again.
- Given a hand-declared bill of 50 for the same merchant and account, when detection runs, then no second series is created.
- Given a detected series at 50 whose two latest single-payment settlements were 60, when detection runs, then a 50 → 60 price change is recorded and the series amount becomes 60.
- Given a quarterly bill last charged 3 months ago, when stale cleanup runs, then the bill stays active.
- Given a monthly dividend of 100 into an investment account, when detection runs, then nothing is suggested.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - RecurringTransactionDetector groups by merchant signature, direction and account. A group needs 2 or more unmatched rows, a dominant amount cluster, a weekly-to-yearly cadence, recent activity and confidence of 0.40 or more.
  - RecurringSuggestionWriter skips:
    - Noise.
    - Patterns already accepted.
    - Patterns already covered by an active plan within 5% and with overlapping descriptions.
    - Patterns dismissed in the last 90 days.
  - RecurringTransactionReview on the rules page:
    - Runs a per-account "Find recurring" scan inside one transaction with a manual PipelineRun, and supersedes older pending suggestions.
    - Accept (with a category) creates an active PlannedTransaction through SuggestionApplier::applyRecurringTransaction and links and categorises the matched rows.
    - Dismiss is also available.
  - AnalysisSuggestions on the providers settings page offers accept, copy and reject.
  - IdentifyRecurringTransactionsStage runs at import only when `budget.recurring_detection` is on, and AnalysisSuggestions hides recurring suggestions otherwise. The rules-page RecurringTransactionReview scan, accept and dismiss are not gated by the flag.
  - Income is handled separately by IncomePatternDetector and SetPayCycleStage.
- **Gap:**
  - No price-change history and no stale cleanup.
  - A dismissal only blocks re-suggestion for 90 days.
  - No per-user on/off switch (the flag gates import-time detection only, and is app-wide).
  - No bill/subscription/installment typing. The rules-page scan takes no lock against concurrent runs; import-time detection is already serialised per user by RunTransactionAnalysisJob.
- **Build it like:**
  - Extend RecurringSuggestionWriter so a rejection is permanent, with no flag, since both the import path and the rules page use it.
  - Add a final PlannedTransactionPriceChange model owned through PlannedTransaction.
  - Add a scheduled stale-sweep and price-change command that dispatches one queued job per user.
  - Take the `analysis-user-{id}` lock key in the rules-page scan, as RunTransactionAnalysisJob does.
  - Conventions:
    - C1, C2, C5, C7, C8.
    - C4: the job carries AttributeJobToUser and is added to the UserActionInventoryTest job list with its audited flag.
    - C6: put the price-change and stale sweep behind a new `budget.*` flag, default off.

### F16. Series status lifecycle (suggested/active/paused/inactive/ended)

#### What the user can do
- See each series' status:
  - suggested: detector output, not yet a bill.
  - active.
  - paused.
  - inactive: retired as stale.
  - ended: dismissed, or deleted after detection.
- Pause and resume with one toggle: an active series becomes inactive, and a series in any other status becomes active.
- Filter All bills by lifecycle. "Paused" shows both paused and inactive series; "ended" shows ended ones.
- Delete a series:
  - A hand-declared series is removed outright.
  - A detected series becomes ended.
- Dismiss a suggestion, which also makes it ended.

#### Data relationships
- Status is per series. Its occurrences, payments and price changes stay attached whatever the status, except on a hard delete of a manual series.
- Only active series generate occurrences or match payments. Suggested series have no occurrences at all.
- Deleting the family, the account or the destination account deletes the series.

#### Edge cases
- Open occurrences left on a paused or inactive series are never labelled due or overdue. They show "Paused" in a Dormant section instead of Past Due.
- Pausing stores inactive, the same value as automatic stale retirement, so the user sees both as "paused".
- Reaching an end date or a payment count stops new occurrences and matching. The status becomes ended only through dismiss or delete.
- Ended is a tombstone: detection will not claim or recreate the series.
- Someone with read-only access to the account can view the series but cannot pause, delete, confirm or dismiss it.

#### Acceptance criteria
- Given an active bill of 100, when the user pauses it and the nightly sweep runs, then no new occurrences are generated.
- Given a paused bill with an open occurrence 10 days past due, when the overview loads, then that occurrence shows under Dormant as "Paused", not as overdue.
- Given a detected series, when the user deletes it and detection reruns, then the series stays ended and is not suggested again.
- Given a hand-declared series with 3 occurrences and 2 payments, when the user deletes it, then the series, its occurrences and its payments are gone.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - PlannedTransaction has an `is_active` flag and an `until_date`. PlannedTransactionManager offers toggleActive, an edit form and delete.
  - Inactive plans produce no occurrences, are skipped by PlannedTransactionMatcher and are left out of the `upcoming` scope.
  - Suggestions move through SuggestionStatus: pending, accepted, rejected, superseded.
  - BnplOrderReview rejecting an order deactivates its plan.
  - The pay-cycle income plan cannot be edited, toggled or deleted from the manager ("Pay cycle income is managed elsewhere").
- **Gap:**
  - There is only active or inactive: no separate stale or ended states and no tombstone.
  - Deleting a plan hard-deletes it and clears links on its transactions.
  - Past open occurrences are dropped rather than kept as dormant.
- **Build it like:**
  - Replace `is_active` with a string-backed PlannedTransactionStatus enum on PlannedTransaction, as a clean cutover of every `is_active` query and scope.
  - Conventions:
    - C2, C3, C5, C7.
    - C8: the migration and backfill must run on MariaDB and SQLite.

### F17. Bills pages (overview, all bills, calendar)

#### What the user can do
- **Overview** (the default view):
  - Past Due.
  - This month: open plus paid, by effective due date.
  - Later: one row per series.
  - Dormant.
  - Next up: the 4 soonest.
  - A month bar showing paid, overdue and still to come.
- **KPIs**, in family currency:
  - Remaining this month and paid this month.
  - Due in the next 7 days and past-due total.
  - Count owed, and count needing action (bills not on autopay).
- **All bills**, every series of every type and status:
  - Search by name or merchant.
  - Filter by payment state (overdue, due, partial, paid), lifecycle (paused, ended) or type.
  - Sort by name or amount.
- **Subscription filter** rollup: active count, monthly equivalent, annual equivalent, and the 10 most recent price changes in the last 12 months.
- **Calendar:**
  - A Sunday-start month grid with a chip per bill on its due day.
  - Month totals: count, expected, paid.
  - Browsing reaches up to 13 months ahead.
- **Bill drawer**, opened from any row:
  - Current cycle, any pending suggestion, and the cadence.
  - Last 12 settled cycles and next 3 dates, plus a 12-month chart.
  - Average, low, high, annualised cost and year-to-date paid.
  - Totals per year for 4 years and the account last paid from.
  - Learned aliases and tolerance.
- **Transaction side:**
  - A transaction's drawer lists the bills it paid.
  - The transactions list shows projected recurring items for the next 10 days, with amount ranges when amounts vary.
- A budget category shows a read-only "bills reserved" figure: the unpaid remainder of open bills in that category for the period.
- Behind Sure's preview-features flag, except the projected recurring items on the transactions list and the "bills reserved" figure, which are not gated.

#### Data relationships
- The overview lists payable series only: active or inactive, positive amount, and either no destination account or a credit-card or loan destination. Inactive series appear only as Dormant. Income, other transfers and suggestions are excluded.
- A recurring payment into a credit card or loan is listed as a bill but excluded from budget calculations.
- The overview shows open occurrences through 90 days ahead, plus anything effectively due since the start of the month.

#### Edge cases
- An amount with no exchange rate is left out of totals and counted as unconvertible.
- A bill snoozed past month end is listed after this month, at its snoozed date.
- An invalid calendar month falls back to the current month. Occurrences for months being browsed are generated on demand.
- Users without preview access are redirected home with a notice.
- A direct link to a bill opens the overview with its drawer.

#### Acceptance criteria
- Given an income series of 2,000 and a bill of 100, when the overview loads, then only the bill is listed.
- Given a recurring 200 payment into a credit card, when the overview loads, then it is listed as a bill.
- Given subscriptions of 10 monthly and 120 yearly, when the subscription filter is selected, then the monthly equivalent is 20 and the annual equivalent 240.
- Given a bill in a currency with no rate, when KPIs compute, then the total excludes it and shows one unconvertible item.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - CalendarView (the calendar page):
    - A Monday-start month grid with up to 3 pips per day, payday markers and the active-cycle band.
    - Planned income and spend still to come this month.
    - Plan-vs-actual, outflows and cash-flow sub-views.
  - Dashboard shows the next three planned items within 3 months, plus Dashboard\PayCycleCalendar.
  - PlannedTransactionManager lists plans per tracked account.
  - TransactionList has a planned/unplanned filter.
  - Past planned occurrences that were never matched are not shown at all (DayActivityLoader).
- **Gap:**
  - No bills workspace: no past-due or overdue list, paid/partial states, bill KPIs, all-bills search and filters, subscription rollup or per-bill history drawer.
  - No "bills reserved" figure on budgets.
- **Build it like:**
  - A new Bills Livewire page over PlannedTransaction. Until F12 and F13 exist it is an interim view that reuses `occurrencesBetween`, with the occurrence-to-transaction pairing pulled out of DayActivityLoader (matchedOccurrenceKeys) and ReconciliationMatcher (unclaimedOccurrences) into one shared class, so Bills cannot disagree with the calendar. Once F12 and F13 exist, it reads paid state from the stored occurrence and its payments, not from a second source.
  - Conventions:
    - C1, C2, C7.
    - C3: a Route::view inside the auth+verified group, with every public method audited, no new `#[NotAudited]` exemptions, and read-only state exposed through `#[Computed]` properties.

### F18. Bill audit

#### What the user can do
- Sure has no audit page. The audit exists only as a tool of the in-app AI assistant: the user asks the assistant to review their bills.
- A "Review with AI" button on Bills starts a chat with a fixed review prompt. It appears only when the user has enabled AI and an AI model is configured.
- The audit returns these sections:
  - Possible duplicates.
  - Price changes in a look-back period: default 12 months, allowed 1–24.
  - Trials converting within 30 days.
  - Renewals within 30 days.
  - Bills overdue by at least one whole cycle.
  - Paused bills still carrying open occurrences.
  - Detections awaiting confirmation.
  - Recurring charges not yet declared.
- The assistant proposes fixes and asks before changing anything. It can then act through its bill create, update and payment tools.
- Behind Sure's preview-features flag.

#### Data relationships
- It is a read-only report over the family's series that the user can reach.
- Each section returns at most 20 items, with a count and a truncated flag.

#### Edge cases
- Duplicate detection is strict: same name (ignoring case and spacing), same amount and same expected day. Two price tiers from one merchant are not duplicates.
- "Overdue" uses the series' own cadence. A weekly bill 8 days late is one cycle overdue; a monthly bill 8 days late is not.
- A look-back outside 1–24 is rejected with an error, not clamped.
- If the family has recurring switched off, the tool returns a disabled result.

#### Acceptance criteria
- Given two active bills named "Gym" for 50 due on the 1st, when the audit runs, then they are flagged as possible duplicates; a third "Gym" bill for 80 is not.
- Given a weekly bill 8 days late and a monthly bill 8 days late, when the audit runs, then only the weekly bill is listed as overdue.
- Given a look-back of 30 months, when the audit is requested, then an error is returned and no report is produced.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:** There is no duplicate, price-change, renewal, overdue or undeclared-charge review of plans, and no assistant chat component to host one.
- **Build it like:**
  - A new final BillAuditor service over PlannedTransaction and pending AnalysisSuggestion rows, shown as a read-only Livewire section on the rules page.
  - Conventions: C2, C3, C5, C7.

### F19. Paycheck plan and pay periods

#### What the user can do
- Open the Paycheck view on Bills. It shows the declared income list with each source's next payday and the next income event.
- See a plan of pay periods:
  - Period 0 runs from today to the first payday, then there is one period per paycheck.
  - The page default is 3 periods. The assistant tool and the month-list markers allow up to 6.
- For each period, see:
  - Income, bills due, amount reserved for later bills, and the obligation total.
  - Safe to spend (income − due − reserved).
  - The income sources and the largest obligation.
  - A short period shows its shortfall instead of a negative safe amount.
- The window before the first payday is measured against cash on hand rather than income.
- The overview's month list gets pay-period markers with summed obligations, but only when income splits the month into more than one period.
- Ask the assistant "which paycheck does this bill come out of" or "can I afford X before my next paycheck".
- With no declared income, the user gets a prompt to add income instead of a plan.
- Behind Sure's preview-features flag.

#### Data relationships
- Paydays come only from active, hand-declared income series, from their open occurrences dated today or later. Detected inflows never create paydays.
- Each open payable bill is assigned to the period containing its effective (snoozed) due date, using its remaining unpaid amount.
- Cash on hand is the total of visible depository accounts the user can reach, in family currency. Tax-advantaged accounts and credit cards are excluded.

#### Edge cases
- Only the part a period's own paycheck cannot cover is reserved from earlier paychecks, nearest first. Overflow that no paycheck can absorb lands on the earliest earlier period that has income, as a shortfall.
- A past-due bill lands whole in the first period.
- A bill snoozed beyond the plan leaves the plan.
- A paycheck that did not arrive is not overdue; it is simply no longer the next payday.
- Paused or detected-only income is listed with a reason but not planned around.
- If every upcoming paycheck falls today, the plan is empty.
- Two sources paying on the same day are summed into one event but still listed by name.
- Unknown cash (no accounts, or an account that can't be converted) makes no claim about the bridge window.
- Obligations with no exchange rate are counted and reported, never treated as zero.

#### Acceptance criteria
- Given no declared income, when the Paycheck view opens, then no plan is shown and the user is prompted to add income.
- Given paychecks of 1,600 in periods 1 and 2 and a bill of 2,000 due only in period 2, when the plan builds, then period 2 funds 1,600 of it and 400 is reserved in period 1.
- Given cash on hand of 40 and a bill of 60 due before the first payday, when the plan builds, then the bridge window is short by 20; with cash of 500 it shows 440 left.
- Given a bill snoozed from period 1 into period 2, when the plan builds, then it is counted in period 2.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - User stores `pay_amount`, `pay_frequency` and `next_pay_date`. PayFrequency is weekly, fortnightly or monthly.
  - User has currentPayCycleBounds, daysUntilNextPay, totalNeededUntilPayday and bufferUntilNextPay (computed against totalAvailable).
  - PayCycleConfigurator writes these and upserts the single `is_pay_cycle_income` PlannedTransaction.
  - SetPayCycleStage with IncomePatternDetector suggests a cycle:
    - It auto-applies at 0.80 confidence or more, and only when no cycle is configured.
    - A changed pattern stays a suggestion.
  - Users can set the cycle through AnalysisSuggestions::acceptPayCycle, the onboarding ConfirmPayCycleStep (through the ConfirmPayCycle action) and the pay-cycle settings page.
  - Dashboard shows buffer, days until pay and total needed.
  - Dashboard\PayCycleCalendar pages through cycles with income, posted and planned totals.
  - CalendarView marks paydays.
- **Gap:**
  - Exactly one pay-cycle income plan (`is_pay_cycle_income` is unique per user) and one cycle at a time.
  - No funding of bills from specific paychecks, no reserves or shortfall per period, and no multi-period plan.
  - Total needed counts every active planned debit occurrence from today to payday at full amount, even if it is already paid.
- **Build it like:**
  - Build multi-period planning in a new final service in Services/Projection.
  - Derive paydays from PlannedTransaction rows that are countable() with countedDirection() Credit, as User::totalNeededUntilPayday does for debits, restricted to income-typed plans (F9) or `is_pay_cycle_income`, so a one-off credit plan such as a refund is not a payday. Show the period view on the Dashboard.
  - Conventions: C1, C2, C3, C5, C7.

### F20. Reminders, notices and calendar feed

#### What the user can do
- Set per-bill reminder days (default 3 before the due date) and an overdue grace period (default 3 after). These drive the upcoming → due → overdue labels.
- See notices on the Bills overview:
  - Trials ending within 14 days.
  - Renewals within 14 days.
  - Price changes in the last 30 days, flagged "material" when large.
  - Urgent items come first (a trial ending within 3 days, or a material price change), then by nearness to today.
- See two insights in the dashboard feed:
  - "Subscription audit": one per expense series with no destination account and an occurrence unpaid for a whole cycle, at most 3.
  - "Cash flow warning": a 30-day projection of combined cash (visible depository accounts in the family currency) against open payable bills with no destination account, so no card or loan payments, plus typical daily spend. It fires when the projected low point falls below 500, and is high priority if the low point is negative.
- Receive high-priority insights as push messages on subscribed devices, if opted in.
- Subscribe an external calendar to a private feed URL:
  - One event per open bill occurrence in the next 90 days.
  - Each event carries name and amount only, at the snoozed date.
- Reset the feed secret, which revokes every family member's feed URL at once.
- No e-mail or push reminder exists for an individual bill coming due.
- Behind Sure's preview-features flag.

#### Data relationships
- Each member has their own feed URL, derived from one family-wide secret that is generated once on first use.
- The feed contains only active, positive-amount series on accounts that member can reach.
- Notices cover only the series the overview lists (active and payable).

#### Edge cases
- The feed returns not-found when:
  - The member has opted out of preview features.
  - The family has switched recurring off.
  - The token is an old-style family token, the raw secret, or garbage.
- The feed uses the family's locale.
- Snoozing moves the effective due date, so an overdue bill can become upcoming again.
- The subscription-audit insight respects the family recurring switch; the cash-flow warning does not.
- The cash-flow warning counts a weekly bill at every occurrence and a partially paid bill only for what remains.

#### Acceptance criteria
- Given a trial ending in 2 days and a renewal in 10 days, when the overview loads, then the trial notice is listed first and marked urgent.
- Given a member who opts out of preview features, when their feed URL is fetched, then the response is not-found.
- Given the family resets the feed secret, when an old URL is fetched, then it is not-found, and a newly issued URL works.
- Given a weekly bill of 20 unpaid for 7 days, when insights are generated, then one subscription-audit insight is created for it.
- Given cash of 1,000 and bills of 600 due within 30 days on top of typical spend, when insights are generated, then a cash-flow warning appears if the projected low point drops below 500.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Dashboard\MonthlyProjection (MonthlyProjectionService and BalanceProjection) marks the first date the primary account's projected balance goes negative. There is no threshold, alert or notification.
- **Gap:**
  - There are no reminders, notifications, notices, overdue states or external calendar feed: no notifications or mail folder, no ICS route, and no reminder or user-notification task in the schedule.
  - Planned items are visible only inside the app: the calendars, the Dashboard and the rules page.
- **Build it like:**
  - Add reminder fields to PlannedTransaction.
  - Add a scheduled command that dispatches one queued job per user, each carrying AttributeJobToUser and sending Laravel notifications through the existing `Notifiable` User.
  - Derive the cash-flow warning from MonthlyProjectionService, adding a low-balance threshold and a notification.
  - Add a named GET route for the ICS feed, authenticated by a per-user token outside the auth+verified group, since calendar clients have no session.
  - Conventions:
    - C1, C2, C5, C7, C8.
    - C4: the job is added to the UserActionInventoryTest job list with its audited flag. The GET feed route is not a write route, so it is not in the inventory.
    - C6: a new budget-config flag, default off.

## Budgets, goals and insights

### F21. Monthly budget

#### What the user can do
- Open the budget for any month and move between months with previous/next links or a month/year picker. The range runs from the earlier of two years ago and the family's oldest entry, up to two years ahead. Out-of-range links are hidden. If the family sets a month-start day, each budget month runs from that day to the day before the next start.
- Opening a month creates an empty, uninitialised budget with a zero line for every category. To set it up, the user enters "Budgeted spending" and "Expected income". An optional autosuggest fills both from the median monthly spending and income in the family's history. It is always offered and shows 0 when there is no history.
- When a month is uninitialised and an earlier initialised month exists, the user chooses "Copy from {month}" or "Start fresh".
- Set an allocation per category. Clearing a subcategory's amount makes it "Shared", meaning it draws on the parent's budget.
- Turn rollover on or off per category. The choice is standing: it propagates to later months, and new months inherit it from the latest initialised month.
- "Move money" moves allocation from one category to another. The total stays the same.
- The page shows:
  - a donut of spent against total;
  - Budgeted/Actual tabs comparing expected and earned income, and budgeted and spent;
  - a category list with an "All / On Track / Over Budget" filter, shown only when something is over;
  - any unallocated remainder as an "Uncategorized" line;
  - a preview-only "Cash on hand" panel. It shows cash-account balances minus money earmarked for goals and is kept out of the allocation arithmetic.
- Category drilldown shows:
  - the 3 most recent transactions, including subcategories;
  - monthly average and median spend;
  - budgeted, rolled over, and left or overspent;
  - bills still unpaid in the period ("bills reserved");
  - in the current month only, a suggested daily spend: available divided by days left, counting today.
- When the family enables personal budgets, a switcher offers the household budget (if enabled), "My budget" and budgets shared with the user. An owner can share a personal budget with another member as view-only or can-edit.
- The assistant and the API can read and update a budget.

#### Data relationships
- A family has at most one budget per month per owner. The owner is either the household or one member.
- A budget has one line per category, unique per budget. Deleting the budget deletes its lines. Deleting a category deletes its lines in every month, and its rollover history with them.
- A budget's currency is fixed at creation from the family currency.
- A parent category's allocation includes its children. Changing a child by some amount changes the parent by the same amount, and the parent keeps its own extra reserve.
- A budget share links an owner to one other member of the same family. Self-shares are not allowed, each pair is unique, and the permission is view-only or can-edit.
- Actual spending is never stored. It is derived from the category's transactions (including subcategories) in the period. A stored rolled-over amount is never negative.

#### Edge cases
- Actual spend is expenses minus refunds per category, floored at 0.
  - Excluded: funds movements between own accounts, credit-card payments and one-time transactions.
  - Included: loan payments and investment contributions.
- Available = allocation + rolled over − actual. Only a surplus carries forward; a deficit stops in its own month.
  - Turning rollover off stops a category both receiving and passing on a carry.
  - Uninitialised months are skipped without breaking the chain. A currency change breaks it.
  - Shared subcategories carry nothing.
- "Allocated" and "available to allocate" ignore rollover, so they stay pure plan figures. When allocated exceeds the total, the donut is replaced by a warning with a "Fix allocations" link.
- Category status:
  - over budget when available is below 0;
  - near limit at 90% or more used;
  - on track when budgeted and not over;
  - an alert when there is spending but no budget.
- Percent spent is 0% when nothing is spent against a zero allocation, and 100% when there is spending but no budget.
- Move money is refused when:
  - the amount is 0 or less;
  - either side is Uncategorized;
  - both sides are the same category, or a direct parent/child pair;
  - the categories are in different budgets;
  - the amount exceeds what the source can send. A parent can send only its own reserve.
- Copy is refused when the target month is already initialised or no earlier budget exists. Copy does not carry the rolled-over amount. Categories missing from the target are skipped, and new categories stay at 0.
- Blank expected income counts as 0. Income surplus reads 100% when nothing was expected but income arrived.
- Personal budgets:
  - A view-only share cannot edit allocations or move money.
  - Viewing a shared budget never creates one on the owner's behalf.
  - A stale household link opens the viewer's own budget when the household budget is disabled.

#### Acceptance criteria
- Given January is initialised with total 2,000, expected income 3,000 and Groceries 400, when the user opens uninitialised February and chooses Copy, then February has total 2,000, income 3,000, Groceries 400 and nothing rolled over.
- Given Groceries has 400 allocated with rollover on, when January spending is 300, then February (also 400 allocated) shows 500 available. When January spending is 500 instead, February shows 400.
- Given parent Food is 600 and its child Groceries is 100, when Groceries is set to 150, then Food becomes 650.
- Given Dining is 200 and Groceries is 400, when the user moves 50 from Dining to Groceries, then they become 150 and 450 and the total is unchanged. A move of 250 from Dining is refused.
- Given a category has 100 allocated, when 95 is spent, then it shows near limit. When 110 is spent, it shows over budget with −10 available.
- Given a 1,000 credit-card payment in the month, when the budget is shown, then spending is unchanged.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Budget is a per-user model:
    - fields: a required name, optional category, `limit_amount` in cents (MoneyCast), a BudgetPeriod of weekly, fortnightly, monthly or yearly, start and end date;
    - deleting the user deletes their budgets; deleting the category nulls the budget's category;
    - no UI, Livewire component or seeder creates budgets.
  - Dashboard::budgetsThisCycle lists the user's budgets with debit spend for the category over the current pay cycle (User::currentPayCycleBounds, CategoryAttribution). The budget-row Blade component turns the bar to "over" when spent exceeds the limit.
  - Budget::remaining() subtracts all-time attributed amounts, summing debits and credits alike, and does not use BudgetPeriod.
  - CategoryReport has a Real/Plan toggle: plan mode shows PlannedTransaction occurrences instead of actuals. There is no plan-vs-actual comparison.
  - Categories carry needs/wants/savings tags: BudgetTag, overridden per user by UserCategoryBudgetTag, resolved by BudgetTagResolver and set in ConfirmBudgetTags. PayeeReview sets a per-payee override (Payee `budget_tag`) that takes precedence.
- **Gap:** No UI creates or edits budgets. There is no monthly budget with a total and expected income, no copy-forward, rollover chain, move money, parent/child allocation sync, near-limit or over-allocation states, or shared personal budgets.
- **Build it like:**
  - New model: a per-user, per-period budget header (pay cycle or month) holding the total and expected income.
    - Budget stays the per-category allocation line and links to its header. Category is global, so lines key on user and category.
    - Reuse Dashboard::budgetsThisCycle and the budget-row component.
  - Actuals come from CategoryAttribution with `excludeUntracked` on, under the Transaction::countable() transfer rule. A transfer between a tracked and an untracked account counts as spend or income; tracked↔tracked and untracked↔untracked never count. Sure's credit-card criterion applies only to the tracked↔tracked case.
  - A Livewire budget page via Route::view.
  - Rollover recompute and move money in one DB transaction.
  - Conventions C1, C2, C3, C5, C7, C8 (and C4 if recompute is queued).

### F22. Goals

#### What the user can do
- **Goals list:**
  - A summary strip: 30-day savings inflow against the prior 30 days, counts on track and behind, and the amount needed per month.
  - Filter chips: all, active, paused, completed. Search appears only when there are more than 6 non-archived goals. Archived goals sit in a collapsed section.
  - Sort order: behind or depleted first, then on track, open-ended, funded, and paused last.
- **Create a goal:**
  - Fields: name, target amount, optional target date, colour, icon and notes.
  - Kind: one-off (reached once) or maintained reserve (a floor to hold).
  - Linked cash or investment accounts, each with an optional earmarked amount.
- A reserve can set its target as "N months of expenses": N × median monthly spend, recalculated monthly and right away on create or change.
- Pause, resume, complete, reopen, archive, unarchive or delete a goal.
  - Complete is only for one-off goals.
  - Unarchive returns the goal to active.
- Record a spend against an active one-off goal, optionally tied to one outgoing transaction. The earmark shrinks by the same amount, and the spend still counts toward progress.
- Pledge "I just saved/transferred X" to a linked account.
  - A pledge stays open 7 days. "Renew" adds 7 days, and the user can cancel it.
  - Incoming money that fits is matched automatically.
- **Goal page:**
  - Progress ring.
  - Projection chart with saved series, target line, projected end and shortfall.
  - Per-account funding breakdown and pending pledges.
  - A status callout: catch-up amount, on track until a date, or the depleted shortfall.
  - Days left or past due, and "last matched N days ago".
- A plan hub shows the budget's top 4 spending categories next to an active-goals card. The assistant can create goals.
- Behind Sure's preview-features flag.

#### Data relationships
- A family has many goals. A goal links one or more accounts.
  - Linked accounts are cash or investment accounts in the same family and the goal's currency. The currency locks once an account is linked.
  - Each account appears once per goal, with an earmark of 0 or more. A blank earmark means the whole balance.
- A goal has many pledges. A pledge may claim one incoming transaction, and a transaction can be claimed by only one pledge.
- Deleting a goal removes only its links and pledges, and clears pledge marks on matched transactions. Accounts, balances and transactions are untouched.
- Deleting an account removes its link. A goal left with no account must be relinked before it can be saved.
- There is no contribution ledger: progress is read from live balances of the linked accounts.

#### Edge cases
- **Progress:**
  - Earmarks are respected. Over-earmarked accounts are scaled down pro rata, and an overdrawn account backs nothing.
  - Investment accounts count contributions, not market gains.
  - Percent is capped at 99 until nothing remains, then shows 100.
- Completing freezes the saved amount, and reopening or unarchiving thaws it.
- **Goal status:**
  - One-off goals are reached, no target date, on track or behind. Reserves are funded or depleted.
  - A reserve cannot be completed, and any target date entered for it is silently cleared.
  - Paused goals and reserves never count as behind.
- **Pace and target:**
  - Pace = net inflow to the linked accounts over the trailing 90 days ÷ 3. Pending and excluded entries and valuations are left out.
  - Monthly target = remaining ÷ (days to target ÷ 30), rounded up to the cent.
- Recording a spend is refused when:
  - the amount is 0 or less;
  - the goal is a reserve or not active;
  - total spends would exceed the target;
  - the amount exceeds the earmark or backing;
  - several accounts are linked and none is chosen.
- **Pledges:**
  - A pledge matches incoming money on its account dated from 5 days before the pledge was made up to the later of 5 days after or the pledge's expiry. The amount must be within 0.50 or 1%, whichever is larger.
  - For manual accounts, the change in a balance update is matched instead. Investment-account balance updates never match.
  - Outflows never match.
  - A second open pledge with the same goal, account and amount is refused. Open pledges expire automatically.
- **Earmarks:**
  - Two goals cannot both claim a whole account.
  - Completed and archived goals release their earmark; paused goals keep it.
  - Unarchiving is refused if another goal claimed the whole account in the meantime.

#### Acceptance criteria
- Given a new goal with no linked account, when it is saved, then it fails with "Pick at least one account to fund this goal."
- Given a one-off goal with target 1,000, a linked balance of 400 and 120 days to the target date, when it is shown, then remaining is 600 and the monthly target is 150. With 600 net inflow in the last 90 days (pace 200), it reads on track.
- Given an open pledge of 200 on a linked account, when 199 arrives two days later, then the pledge is matched. When an outflow of 200 arrives instead, it stays open.
- Given a goal completed at 1,000, when the linked balance later drops to 700, then the goal still shows 1,000.
- Given a maintained reserve, when the user tries to complete it, then the action is refused.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none.
  - A grep for goal, savings target, target amount and sinking fund in app, migrations, config, routes and views found nothing.
  - The closest pieces only classify money and track no target: the LongTermSavings value of AccountGroup and the Savings value of BudgetTag.
- **Gap:** Sure lets users set savings targets and floors backed by earmarked account balances. It tracks pace and status, records spends and matches pledges to incoming money. Can-I-Budget has none of this.
- **Build it like:**
  - New models:
    - Goal: user-owned, with kind, status and target in cents.
    - A goal–account join to Account, with the earmark in cents.
    - An optional pledge model.
  - Progress is computed from Account balances.
  - A Livewire goals page via Route::view, behind a budget-config flag.
  - Pledge expiry runs from a scheduled command that dispatches one queued job per user, each carrying AttributeJobToUser.
  - Conventions C1, C2, C3, C4, C5, C6, C7, C8.

### F23. Insights feed

#### What the user can do
- Open the insights page, see the top 3 insights on the dashboard, and see a header badge with the unread count, capped at "9+".
- Opening the page marks every active insight read. A link prefetch on hover does not. The "New" marker still shows for that view.
- "Got it" hides an insight until its numbers change.
  - The next insight fills the freed slot.
  - When none are left, the page shows an empty state and the dashboard widget disappears.
  - There is no undo toast. Screen readers hear "Insight dismissed".
- "Check for new insights" queues a refresh. The button stays pending until it finishes, and the list updates live.
- Insights are ordered by priority (high, medium, low), then newest first. Each has a type label and a link to the relevant screen ("View budget", "Go to account").
- There are nine types: spending anomaly, cash-flow warning, net-worth milestone, subscription audit, savings-rate change, idle cash, budget at risk, budget on track, and maintained goal depleted.
- High-priority insights also send a generic mobile push ("Open Sure to review…") to opted-in members. A read-only API lists the feed.
- Behind Sure's preview-features flag.

#### Data relationships
- A family has many insights, shared by all its members. Each has a type, a priority, a status and a dedup key that is unique within the family.
- An insight may refer to an account. It is shown only while every active member can see that account, and it is hidden if the account is private or pending deletion.
- Status is one of active, read, acknowledged or expired.

#### Edge cases
- **Triggers, part 1:**
  - Spending anomaly: parent categories only. Spend projected at the current pace is at least 25% away from a 3-month baseline. The baseline must be at least 50, the month at least 7 days in, and there are at most 3. It is high priority at 50% or more.
  - Cash-flow warning: the lowest projected balance over the next 30 days (recurring bills plus the usual daily spend) is below 500. It is high priority when that low point is below zero. Zero is not a separate trigger.
  - Net-worth milestone: net worth crossed 10k, 25k, 50k, 100k and so on, up to 10M, within the last 30 days.
- **Triggers, part 2:**
  - Subscription audit: a recurring item is overdue by at least one full cycle of its own schedule, about 30 days for monthly and 7 for weekly (at most 3). It is off when recurring tracking is off.
  - Savings-rate change: the last two full months differ by at least 5 points. It is high at 10 or more.
  - Idle cash: 5,000 or more in a family-currency cash account untouched for 60 days (at most 2).
- **Triggers, part 3:**
  - Budget at risk: any parent category is over or at 90% or more. It is high if any category is over, and lists up to 3 names.
  - Budget on track: only after half the month has passed, only when at least one parent category is budgeted, and only when nothing is flagged.
  - Goal depleted: an active reserve is below its floor. It is high priority, at most 2.
- **Regeneration:**
  - Each check stores comparison values. When the key and those values are unchanged, the insight keeps its read or acknowledged state, and only its title and displayed figures refresh.
  - Any change in the comparison values resets it to active and rewrites the text, so an acknowledged insight comes back.
  - When a condition clears, the insight expires, but only for types whose check ran successfully. If the condition returns, it becomes active again.
- Budget insights and several other types are keyed per month, so acknowledging July says nothing about August. Net-worth milestones stay acknowledged permanently.
- Undoing an acknowledgement restores the insight as read, not new, and does nothing to an expired insight.
- Generation runs nightly per family, only where at least one member has opted into previews. A run is skipped while another is in progress for that family.
- Figures are computed directly. An AI model writes only the wording, with a template fallback.

#### Acceptance criteria
- Given 3 active insights, when the user opens the insights page, then all 3 become read and the badge shows no unread count.
- Given 12 unread insights, when any page renders, then the badge shows "9+".
- Given a category baseline of 400 per month, when 300 is spent by mid-month (projected 600, +50%), then a high-priority spending anomaly is created.
- Given an acknowledged insight, when the next run produces the same key and comparison values, then it stays hidden. When the comparison values differ, it reappears as active.
- Given an insight naming an account that one member keeps private, when another member opens the feed, then that insight is not shown.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - TransactionAnalysisPipeline stages write AnalysisSuggestion rows. They have a SuggestionType (PrimaryAccount, PayCycle, RecurringTransaction, UserRule) and a SuggestionStatus (Pending, Accepted, Rejected, Superseded); the pipeline marks older pending ones Superseded.
  - The AnalysisSuggestions component lists them for accept or reject.
  - Dashboard\MonthlyProjection marks a "First negative balance" date from MonthlyProjectionService.
  - Dashboard budget rows flag spending over the limit.
  - The dashboard already shows typed, conditional attention cards:
    - a first-import card with "N need your attention", from FirstImportSummary;
    - a "Check last month's statements" card, from Dashboard::statementsDue;
    - a pending-transfers card, from TransferReviewQueue;
    - the buffer hero ("YOU ARE SHORT BY"), a cash-flow warning analogue.
- **Gap:** There is no feed of typed, prioritised observations such as anomalies, milestones, idle cash or savings rate. There is also no read/acknowledged/expired lifecycle, unread badge, resurfacing when figures change, or scheduled regeneration.
- **Build it like:**
  - A new user-owned Insight model: type, priority and status enums, a dedup key unique per user, and JSON figures.
  - Filled by a scheduled command that dispatches one queued job per user, each carrying AttributeJobToUser. The job runs one generator per type, following the PipelineStages pattern.
  - A Livewire feed page and a dashboard card, behind a budget-config flag. The card follows the same conditional `x-cib.card` pattern as the existing attention cards in the dashboard view. Cash-flow and "needs attention" insights must not duplicate those cards.
  - Conventions C1, C2, C3, C4, C5, C6, C7, C8.

## Rules and automatic categorisation

### F24. Rule definition, conditions and groups

#### What the user can do
- Create, edit and delete transaction rules in a modal form. Delete every rule at once after a high-severity confirmation.
- Give a rule an optional name, an active toggle, an optional effective date ("on or after"), a stop-processing flag, conditions and actions.
- Build conditions on nine fields: transaction name, amount, transaction type, merchant, category, tag, notes, account, and provider-supplied extra details.
- Text conditions (name, notes) offer these operators: contains, does not contain, starts with, ends with, matches regex, equal to, not equal to, is empty, is not empty.
- Amount offers greater than, greater or equal, less than, less or equal, equal and not equal. Merchant, category, tag and account offer equal, not equal, is empty and is not empty. Transaction type offers only "equal to" income, expense or transfer.
- Add one level of condition group, where "all" or "any" of its sub-conditions must match. Top-level conditions are always ANDed.
- See the rule list. Each row shows IF (first condition, plus "and N more"), THEN (first action, plus "and N more") and FOR ("all past and future transactions" or "on or after" a date). Inactive rules are dimmed.
- Guests can view the list but cannot change it.

#### Data relationships
- A family has many rules. A rule has many conditions, actions and run records. A condition group has many sub-conditions.
- Deleting a rule deletes its conditions, actions and run records.
- Deleting a category, tag or merchant does not delete rules that reference it.
- Rules target transactions only; no other record type can be a rule's subject.
- A rule only looks at transactions that meet all of these:
  - in accounts that are active or draft (not disabled or being deleted)
  - not the parent of a split
  - dated on or after the rule's effective date

#### Edge cases
- A rule must have at least one action, and cannot have two actions of the same type.
- A group cannot contain another group ("Compound conditions cannot be nested"). An empty group does not narrow the match.
- Contains, starts with, ends with and regex ignore case. Equal to is case-sensitive.
- Before comparing, whitespace is trimmed and collapsed on both sides, except for regex. Wildcard characters such as percent and underscore in a value match literally.
- Amounts compare by absolute value, so a rule on 100 matches both a 100 expense and a 100 income.
- Unset fields count as non-matches for the negative operators:
  - "Does not contain" matches an empty field.
  - "Not equal" on merchant, category or account matches an unset field.
  - Tag "not equal" matches untagged transactions.
- Tag conditions behave like "has this tag":
  - Two ANDed tag conditions require both tags.
  - An "any" group never counts a multi-tagged transaction twice.
- Limits on regex patterns:
  - At most 200 characters, must compile, and back-references are not allowed.
  - A pattern is stopped after 5 seconds. A timeout fails the run, and previews treat it as no match.
- The details condition offers only contains, equal to (which behaves as case-sensitive contains) and is empty. It searches values, not keys.
- A value is required except for is empty and is not empty. Null bytes are rejected; Unicode, tabs and newlines are allowed. An unsupported condition type is labelled "Unsupported (type)" in the list.

#### Acceptance criteria
- Given a rule with conditions but no actions, when I save it, then it fails with "must have at least one action".
- Given a rule "name starts with AMZN", when it runs over "Not AMZN" and "AMZN Mktp", then only "AMZN Mktp" matches.
- Given a rule "amount greater than 50", when it runs over a 100 expense and a 100 income, then both match.
- Given an "any" group of "name contains walmart" and "name contains target", when the rule runs, then transactions containing either name match. With "all" instead, then neither single-name transaction matches.
- Given a rule "tag not equal to Holiday", when it runs, then untagged transactions match.
- Given a rule with effective date 1 March, when it runs, then a matching transaction dated 28 February is untouched.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - UserRule holds a list of triggers, plus strict_mode (all vs any), is_active and order.
  - Every UserRule belongs to a UserRuleGroup with is_active, stop_processing and order. Deleting a group deletes its rules, and deleting a user cascades to both.
  - RuleTriggerField has 11 fields, including description, clean description, merchant name, merchant key, amount in cents, direction, account, category, source, ANZSIC code and notes.
  - RuleTriggerOperator has 14 operators; is empty and is not empty need no value.
  - RuleEvaluator matches text without regard to case.
  - UserRuleManager handles create, edit, delete and search. Its validation requires at least one trigger and one action.
- **Gap:**
  - Missing features: effective date, regex operator, tag condition, delete-all, duplicate-action guard.
  - Different matching: a rule is wholly all or wholly any, with no nested any/all group inside an AND rule. Text equals ignores case.
- **Build it like:**
  - Add a nullable effective date to UserRule.
  - Allow one nested all/any group entry in the triggers list. Every consumer that reads `triggers` as a flat list of `{field, operator, value}` entries needs group-aware handling: RuleEvaluator (matches, evaluateTrigger), the UserRuleManager validation (`triggers.*.field`, `.operator` and the per-index value rule), RuleTester, ManualContradictionChecker and CategoryRuleMiner (existingTriggerValues reads each `value`, so values inside a group would be missed and duplicate mined rules created). Alternatively, store the group in a separate column.
  - Add a regex case to RuleTriggerOperator. RuleEvaluator treats a backtrack-limit error as no match; UserRuleManager validates at save that the pattern compiles and is within the length cap.
  - Add a tag field once tags exist (F7).
  - Conventions: C5, C8, C3 (validation in UserRuleManager), C7 (RuleEvaluatorTest cases).

### F25. Rule actions and field locks

#### What the user can do
- Set category, set merchant, set name (renames the transaction) and set investment activity label (Buy, Sell, Dividend and similar).
- Set tags: add one or more tags without removing the tags already on the transaction.
- Exclude the transaction from budgeting and reports.
- Set as transfer or payment to a chosen account. This creates the missing opposite-side transaction there and confirms the transfer straight away.
- Allocate an international transaction fee to its matching purchase.
- Send an email notification digest about newly matching transactions.
- Use AI auto-categorise and AI auto-detect merchants (see F29).
- Lock a field against rules and provider enrichment by editing it by hand.

#### Data relationships
- Each action belongs to one rule, and a rule holds at most one action of each type.
- Set-category, set-merchant and set-tags actions point at the family's own categories, merchants and tags. A deleted target makes the action do nothing; the rule itself is not deleted.
- The transfer action creates one new transaction in the target account:
  - same date and currency, opposite amount
  - named "Transfer to <target>" or "Transfer from <source>" by sign, or "Payment to/from" when the target is a liability
- The email action keeps a record, per rule, of which transactions have already been emailed.
- Locks are per field per transaction: name, excluded, category, merchant, tags and activity label.

#### Edge cases
- Set category, merchant, name and activity label skip locked fields unless the run overrides locks. The transfer action ignores locks.
- A blank name, a deleted category or merchant, or an unknown activity label makes that action do nothing.
- A transaction that is already a transfer is left alone by the transfer action.
- How the fee action works:
  - It pairs each "Int Tran Fee - merchant" row with the purchase in the same account on the same day whose description starts with the fee's merchant words.
  - When several same-day pairs exist, they are matched by ranked amount.
  - The fee takes the purchase's category and is excluded.
  - Both rows get cross-referencing notes, and a re-run does not repeat them.
- The fee action changes nothing in three cases:
  - no purchase matches
  - the purchase is uncategorised
  - the same-day fee and purchase counts differ
- How the email action avoids repeats:
  - It sends only while the rule is active, and at most once per transaction per rule.
  - Switching the rule on, or adding the action, first marks all current matches as delivered without emailing.
  - If that baseline times out, the rule is switched off and the user is told "This rule is still off…".
- How locks are enforced:
  - Scheduled and automatic runs respect locks.
  - The web "Re-apply rule" and "Apply all" override locks for the rules being applied; rules above them still respect locks.
  - The assistant respects locks unless it is told to override.

#### Acceptance criteria
- Given a transaction tagged A and a rule that sets tag B, when the rule runs, then the transaction has tags A and B.
- Given a category edited by hand and a scheduled run of a rule that sets another category, when the run finishes, then the category is unchanged and the row counts as blocked.
- Given the same transaction, when I use "Re-apply rule" on the rules page, then the category changes.
- Given a fee row whose matching purchase has no category, when the rule runs, then the fee is unchanged.
- Given a rule "set as transfer to Savings" matching a 100 outflow, when it runs, then Savings gains a 100 inflow on the same date and the pair is a confirmed transfer.
- Given an email rule switched on while 10 transactions already match, when one new matching transaction syncs, then one email is sent and it covers only the new transaction.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - RuleActionType has seven actions: set category, set description, set clean description, append notes, set notes, link to planned transaction, and fold into parent (fees through TransactionFeeFolder).
  - RuleActionExecutor runs them; RuleActionLookups caches lookups for dry runs. A hidden or missing category makes set category do nothing.
  - Transaction category_source (CategorySource) with categoryProtectedFromRules means a rule never overwrites a category set by hand, a split, or a transfer leg.
  - CleanDescriptionSource stops the import pipeline from replacing a name that a person or a rule set. "Apply now" (UserRuleApplier::applyToHistory) overrides that clean-description lock, while the pipeline respects it.
  - TransferRule stores a description pattern for an account plus a counterpart account, and is remembered on confirm. TransferRuleList lists and deletes them. TransferDetector::runRules applies them with TransferLinkSource::Rule, in TransferDetectionStage before UserRulesStage. When the counterpart is untracked it creates the opposite leg (opposite amount, same date) through TransferLinker::linkToUntrackedAccount. When the counterpart is tracked it pairs with the single real row.
- **Gap:**
  - Missing actions: set merchant, tags, exclude from reports, investment label, email. A transfer is created only by TransferRule, which matches on description text per account; no transfer action exists on a general UserRule condition.
  - Locks: a manual apply already overrides the clean-description lock. The category lock (CategorySource::Manual) can never be overridden. There are no other per-field locks.
- **Build it like:**
  - Add new RuleActionType cases executed in RuleActionExecutor.
  - Extend the CategorySource provenance pattern to per-field sources, as CleanDescriptionSource already does.
  - Extend TransferRule matching, or have a UserRule action write a TransferRule-equivalent link through TransferDetector. Keep "real rows win" and create a mirror leg only on untracked accounts. Transaction::countable() is unchanged.
  - Send the email through a Laravel notification on the User, from a queued job carrying AttributeJobToUser. A delivery record per user, rule and transaction handles the dedupe.
  - Conventions: C1, C2, C4, C5, C7, C8.

### F26. Rule activation, preview, apply, ordering and auto-apply

#### What the user can do
- Switch a rule on or off with the toggle on its list row; it saves immediately. Rules made in the form or the quick-categorise flow start active.
- See a confirmation before applying, showing "apply to N transactions". For a single rule it also says how many active rules above it run first.
- Use "Re-apply rule" on one rule or "Apply all" for the family. Both run in the background, and pressing Apply on a rule switches it on.
- See, in "Apply all", the number of unique transactions affected across all rules. Overlaps are counted once and rules that time out are skipped.
- See, when a selected rule uses AI, an estimated cost and the selected model, or a notice that cost estimation is unavailable.
- Reorder rules by drag-and-drop or keyboard; position sets priority.
- View run history: time, manual or scheduled, status, rule name, and queued, processed, modified and blocked counts. Blocked means processed minus modified.
- Rely on active rules running automatically after every sync.

#### Data relationships
- A rule has many runs. Each run records whether it was manual or scheduled, its status (pending, success or failed) and its counts.
- Rules have one family-wide order. New rules go to the end, and ties break by creation time.
- Reordering must list every family rule exactly once; a stale list is rejected.
- After each family sync, transfer matching runs first, then all active rules in order as a scheduled run. Provider-capture batches and the once-a-day login auto-sync start the same sequence.
- Only one rule run per family at a time. A run that finds another in progress retries every 30 seconds, up to 20 times.

#### Edge cases
- Top rule wins. Once a rule sets category, merchant, name, excluded, transfer or activity label on a transaction, lower rules leave that field alone in that run. This holds even if the higher rule changed nothing or the field was locked.
- Some actions reserve nothing: tags (they add, never replace), any action that cannot act, the AI actions and the fee action.
- A rule with stop processing keeps every lower rule away from the transactions it matched.
- Applying one rule first runs the active rules above it, so the result equals a full run. Rules below it do not run.
- Sync runs only active rules. "Apply all" also runs inactive rules' field changes, but their emails never send.
- All of a rule's actions work on the set matched at the start, so a rename does not hide the transaction from the rule's later actions.
- How failures are handled:
  - A failing rule is recorded as failed, and the remaining rules continue.
  - The failed rule still reserves its fields for lower rules.
  - A regex timeout on a single-rule apply is not retried.
  - Runs with AI actions stay pending until their background batches report back.
- Each sync re-applies active rules to every matching transaction in range, not only new ones.
- A CSV import or a hand-entered transaction does not run rules by itself.

#### Acceptance criteria
- Given two rules matching one transaction, the top one setting category A and the one below setting B, when all rules run, then the category is A. After swapping their order, it is B.
- Given a top rule with stop processing that matches a transaction, when all rules run, then a lower rename rule leaves that transaction's name unchanged.
- Given an inactive rule, when a sync completes, then the rule does not run.
- Given two rules each matching 60 transactions with 20 in common, when I open "Apply all", then the confirmation says 100 transactions.
- Given an active rule and a newly synced matching transaction, when the post-sync step completes, then the rule has applied and a scheduled run record exists.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - UserRuleManager toggles UserRule is_active and is_auto_apply, and UserRuleGroup is_active and stop_processing. It also moves groups and rules up and down.
  - The RuleTester dry run (testRule, not audited) groups a draft rule's matches into RuleTestResult rows:
    - three buckets: not yet applied, skipped (categorised by hand, fold not possible), already applied
    - up to 50 newest RuleTestRow entries per bucket
  - "Apply now" calls UserRuleApplier, for active rules only. "Run rules now" queues RunTransactionAnalysisJob, which is unique per user and does not overlap.
  - UserRulesStage runs active groups and their rules in order after every ImportCsvTransactionsJob and SyncRedbarkFeedJob:
    - is_auto_apply rules apply once per rule-transaction pair, recorded as a PipelineAuditEntry.
    - Other rules become AnalysisSuggestions, which the user accepts in AnalysisSuggestions.
  - RuleBacklogApplier, also available as rules:apply-backlog, categorises uncategorised rows.
  - TransferDetectionStage (rules only) already runs before UserRulesStage, matching Sure's transfer-first order.
- **Gap:**
  - No priority between rules in a group: every matching rule runs, a later one can overwrite an earlier rule's category, and stop processing works per group only.
  - "Apply now" runs inside the request without the rules above it. There is no count confirmation, apply-all count or per-rule run history. A manual apply already overrides the clean-description lock; the category lock (Manual) is never overridden.
- **Build it like:**
  - Track claimed fields per transaction in UserRulesStage and UserRuleApplier. Record claims for every matching higher rule before lower rules run, including pairs skipped by the auto_applied dedup and rows protected as Manual.
  - Move "Apply now" onto a queued job carrying AttributeJobToUser, added to the job list with its audited flag. It shares the WithoutOverlapping key of RunTransactionAnalysisJob (`analysis-user-{id}`), so only one rule run per user is active.
  - Derive per-rule run history from PipelineRun and PipelineAuditEntry, adding processed and blocked audit actions. "Apply now" creates a PipelineRun with PipelineTrigger Manual. There is no RuleRun model.
  - Reuse the RuleTester counts for the apply confirmation.
  - Conventions: C2, C3, C4, C5, C7, C8.

### F27. Rule creation from a transaction (prompts, wizard)

#### What the user can do
- After changing a transaction's category, see a prompt offering "Create rule". It opens the new-rule form prefilled with a "name contains <merchant>" condition and a set-category action for the new category.
- Dismiss the prompt with "Hide for today", which pauses suggestions for 24 hours, or tick "Don't show this again".
- Turn the prompts back on in preferences with "Suggest rules when categorizing". Turning them on also clears any "hide for today" pause.
- Open the Quick Categorize wizard. It walks through uncategorised transactions one group at a time, grouped by merchant or name, and shows the total still uncategorised.
- In the wizard, pick one category for the whole group or assign rows one by one. Edit the filter text and watch the matches update live, optionally restricted to income or expense.
- Tick "create rule" in the wizard to save an active rule named after the group, with the condition "name contains <key>" (plus the type condition when one is chosen) and a set-category action.

#### Data relationships
- The prompt's "hide until" time and "never show" flag belong to the user, not the family. Each member of a family dismisses independently.
- A rule created from the prompt or the wizard is an ordinary family rule. Once saved, nothing links it back to the transaction or group that produced it.
- The wizard writes the chosen category straight onto the selected transactions. The rule only governs later runs.

#### Edge cases
- Guests never see the prompt.
- The prompt appears only when the category actually changed to a non-empty value.
- The prompt is skipped when an active rule that sets this same category already matches the transaction. An inactive rule, or a rule for the same category that does not match this transaction, still allows the prompt.
- The wizard never offers split-parent transactions.
- When the wizard's rule would be a duplicate or invalid, it is skipped with the alert "Transactions categorized, but the rule could not be created (it may already exist).", and the categorisation of the selected rows still goes through.
- When no uncategorised groups remain, the wizard returns to the transaction list with the notice "All transactions are categorized".

#### Acceptance criteria
- Given prompts are enabled and no active rule matches, when the user changes a transaction from uncategorised to "Groceries", then a "Create rule" prompt appears and opens a form prefilled with "name contains <merchant>" and set category "Groceries".
- Given the user pressed "Hide for today" 2 hours ago, when they recategorise another transaction, then no prompt appears. When they recategorise one 25 hours later, the prompt appears.
- Given an active rule "name contains COFFEE → Dining" matches the transaction, when the user sets it to Dining, then no prompt appears.
- Given a wizard group "ACME" with 10 uncategorised rows and "create rule" ticked, when the user assigns "Utilities" to all 10, then all 10 are categorised and one active rule "name contains ACME → Utilities" exists.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - TransactionModal has a "categorise matching" checkbox. It defaults to on for a transaction that was uncategorised and is neither a transfer nor a split. Its match value comes from CategoryRuleGenerator::suggestMatchValue and can be edited. ManualContradictionChecker warns about rows the user filed elsewhere by hand, and the user must consent before those rows move.
  - TransactionList offers "create rule from selection" (openRulePanel / createRuleFromSelection). Its CategoryRulePreview counts rows in the selection, rows beyond it, rows that would change, and rows kept because the user set them by hand. Its merchant mode is already an uncategorised-triage view: it runs only when the category filter is uncategorised, expands one cluster at a time, lets rows be ticked individually or all selected (selectCluster), has an incoming/outgoing direction filter, and creates a rule from the selection with an editable match value and a preview.
  - CategoryRuleGenerator creates an active, auto-apply UserRule in the "Auto-categorisation" UserRuleGroup and sweeps history through UserRuleApplier.
  - PayeeReview, through PayeeConfirmer, creates one merchant-key rule per confirmed payee and updates that same rule when the payee is answered again.
- **Gap:** There is no prompt after a manual category change with "hide for today" / "don't show again" and a preference to re-enable it. The merchant mode has no guided next-group flow, no remaining-uncategorised total and no "create rule" checkbox inline with the assignment. Rules are created and applied immediately instead of opening a prefilled rule form.
- **Build it like:**
  - Store the dismissal timestamp and the disable flag in the F44 preference store (C5).
  - Show the prompt in TransactionModal after a Manual category change, reusing CategoryRuleGenerator::preview.
  - Extend TransactionList merchant mode instead of adding a component: next-cluster navigation, a remaining-uncategorised count, and a create-rule toggle on applyCategoryToSelection.
  - New public Livewire methods are audited by default and need no inventory change (C3, C4). Writes are user-scoped (C2), and the rule plus the recategorisation share one transaction (C8). Pest tests (C7).

### F28. Rule import/export

#### What the user can do
- Import rules from a CSV through the import wizard. The columns are name, resource type, active, effective date, conditions and actions, with conditions and actions written as JSON lists. A downloadable template is provided.
- Re-import an edited file to update existing rules in place, matched by name.
- Export rules as part of the family data export. The export holds a rules CSV in run order and a versioned structured record of each rule, including its position and stop-processing flag.
- Restore rules from the structured record with the family data import. It brings back active state, effective date and stop processing. New rules are added after the family's existing rules in the exported order, and rules restored earlier are updated in place and keep their position.

#### Data relationships
- An imported rule belongs to the family that ran the import.
- Category, merchant and tag names in the file are looked up by exact name within the family. Any that are missing are created during the import.
- A named row replaces the conditions and actions of the family's same-named rule of the same type. Unnamed rows always create new rules.
- The structured record refers to categories, tags and merchants by their exported identity, and the restore remaps them to the newly created records.

#### Edge cases
- A tag list is split on commas, but a quoted tag name that contains a comma stays whole.
- "Active" accepts true/1/yes/y and false/0/no/n. Any other value imports as inactive.
- A date that cannot be parsed means the rule has no effective date.
- Over-escaped or slightly malformed JSON is cleaned up and accepted. JSON that is genuinely invalid fails the import with an error naming the field.
- An empty actions list, an unsupported resource type, or null bytes fail the import.
- The CSV has no columns for stop processing or position, so a CSV round-trip loses both.

#### Acceptance criteria
- Given the family has 5 rules, one named "Groceries", when a CSV with a row named "Groceries" and a new condition is imported, then that rule's conditions are replaced and the family still has 5 rules.
- Given a CSV action "set category Pets" and no category named "Pets", when it is imported, then a "Pets" category is created and the rule points at it.
- Given a CSV row whose actions list is empty, when it is imported, then the import fails and no rule is created from that row.
- Given a family with 3 ordered rules, one with stop processing on, when the data is exported and restored into an empty family, then the 3 rules come back in the same order with stop processing still on.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:** UserRuleManager has no way to download or upload rule definitions, and no data export exists anywhere in the app. The closest analogue is CategorySeedRules, a curated list maintained in code, not something users can import or export.
- **Build it like:**
  - Add export and import actions to UserRuleManager that serialise UserRuleGroup and UserRule, including order, is_active, stop_processing, strict_mode and is_auto_apply. Triggers and actions are validated against RuleTriggerField, RuleTriggerOperator and RuleActionType.
  - Refer to categories by full path, resolved the way CategorySeedRules does: an unresolvable path is skipped and reported. Do not create missing categories, because Category is global (C2).
  - Account triggers (RuleTriggerField::AccountId) and planned-transaction actions (RuleActionType::LinkToPlannedTransaction) are exported by name or description and re-resolved within the importing user's own records (C2). An unresolvable reference skips the rule and reports it.
  - The whole import runs in one DB transaction (C8). Actions are named and audited (C3, C4). Pest tests use fixture files (C7).

### F29. AI-assisted categorisation and merchant detection

#### What the user can do
- Add an "auto-categorise" action to a rule. It is offered only when a categorisation provider is configured, or when the rule already has the action.
- Add an "auto-detect merchants" action to a rule. It is offered only when a language-model provider is configured.
- See an estimated cost before applying such a rule. Self-hosted installs also show a cost warning on the rules page and a cost estimate per 20 transactions on the action label. Hosted installs show no estimate on the label.
- Use "Reset AI cache" in the rules page menu to unlock AI-set values the user has not since changed, and clear the cached answers.
- A family member opted into preview features can choose an experimental classification provider and set a confidence threshold and a shadow-comparison rate. An environment setting overrides each of these. The categorisation actions themselves are not preview-gated.

#### Data relationships
- AI answers are written onto the family's transactions, the fields are locked, and an enrichment record caches each answer.
- The local model trains only on the family's own categorised transactions: the 5,000 most recent at most.
- A detected merchant is either an existing family merchant matched by name or website, or a new family-scoped merchant.
- The batched background work reports its modified count back to the rule run that started it.

#### Edge cases
- A local naive-Bayes model goes first and only labels uncategorised transactions. It needs at least 20 categorised transactions across at least 2 categories, and it accepts an answer only at 70% confidence or higher. The AI provider receives only what remains. When the local model handles everything, the AI provider is never called.
- The confidence threshold holds back low-confidence answers from the classification service only, and leaves those transactions unlocked. Language-model answers carry no confidence and are never held back.
- The shadow comparison asks the unused provider for diagnostics only, and never applies its answer.
- A repeat run skips rows already AI-categorised and locked.
- Auto-categorise fails with an error when the family has no categories.
- Merchant detection skips transactions whose merchant is locked. AI-created and family merchants are left unchanged. A provider-supplied merchant only gets a missing website and logo filled in.
- Rules with an AI action can be previewed and applied only from the web, not through the assistant tools.

#### Acceptance criteria
- Given 25 categorised transactions across 3 categories and one uncategorised match, when an auto-categorise rule runs and the local model is at least 0.7 confident, then the match is categorised and no AI call is made.
- Given only 10 categorised transactions, when the rule runs, then the local model is skipped and the provider categorises the match.
- Given a confidence threshold of 0.8 with the classification service, when it answers at 0.6, then the transaction stays uncategorised and unlocked.
- Given an AI-set category the user never touched, when "Reset AI cache" is used, then the category is unlocked and the next run may categorise it again.
- Given an uncategorised transaction with no merchant, when auto-detect merchants runs, then the transaction gets a family merchant that is either reused by name or website, or newly created.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Categorisation runs as a pipeline stage, not a rule action. PayeeSuggestionStage calls PayeeSuggester when budget.jev_categorisation is on and services.typesafe.api_key is set. PayeeSuggester asks TypeSafe Jev (TypeSafeService via TypeSafeServiceContract) once per merchant key, using one representative uncategorised debit and sending only the digit-masked merchant name. It skips credits, transfers, splits, merchant keys that already have a Payee, and rows an active category rule already matches. When the user has at least 2 confirmed payees in the same brand industry and more than half agree, it adds their usual category as a hint. It asks for the root category, then a category within it.
  - A suggestion is applied as CategorySource::Suggested only when confidence is at least 0.80 and the top answer is at least 3.0 times the runner-up. Otherwise the payee is queued in PayeeReviewQueue for PayeeReview.
  - Merchant brands: EnrichMerchantBrandsJob and ResolveMerchantBrandJob look up brands through ContextDevService, filtered by DescriptorGate and capped by ContextDevCreditBudget (services.context_dev.daily_credit_cap). BrandNameWriter fills display names. TransactionList offers identifyMerchant and updateVisibleMerchants, gated by services.context_dev.enrichment_enabled, and vetoMerchantBrand, which is not gated.
  - Supporting pieces: the categories:jev-eval command measures accuracy offline, and CategoryRuleMiner learns rules from the user's own history.
- **Gap:** AI cannot be attached to a chosen rule's matches. There is no local model run before the provider, no shadow comparison, no cost estimate, and no reset for Suggested categories: revertMachineCategories only reverts Rule-sourced ones. A merchant lookup gives a display name, logo and domain (MerchantBrand), but there is no merchant record users can reuse.
- **Build it like:**
  - Add a new RuleActionType case that queues a job carrying AttributeJobToUser, which asks Jev once per matched merchant key through PayeeSuggester. RuleActionExecutor::wouldChange treats it as no change, so "Apply now" and the RuleTester dry run make no TypeSafe calls in the request. Document that credits, known payees and rule-matched rows are skipped, or extend PayeeSuggester with a re-ask path.
  - Extend revertMachineCategories to cover CategorySource::Suggested.
  - Keep both behind budget-config flags (C6).
  - Any new job carries AttributeJobToUser and is added to the job list with its audited flag (C4). TypeSafe calls are faked with Http::fake; Context.dev is mocked through ContextDevServiceContract or a Guzzle MockHandler (C7).

### F30. Category/tag auto-assignment that is not a rule

#### What the user can do
- Let linked bank connections that supply a category have it matched automatically to one of the family's existing categories. Each such account has an on/off "enable category matcher" toggle. Only three provider types have the matcher, and the toggle shows only for those three.
- During a spreadsheet import, map each distinct category and tag value in the file to an existing one, or choose "Add as new".
- Import hierarchical values such as "Parent:Child". This creates a top-level parent and a child that takes the parent's colour. New categories get an icon suggested from their name.
- Bootstrap a set of default categories, such as income, groceries and food and drink, for a new family.
- Rely on manual edits always winning. Name, category, merchant and notes supplied by a provider are applied only to fields the user has not locked.

#### Data relationships
- The matcher maps a bank category to a family category by exact name first, then by aliases (plural/singular and spacing variants), then by parent aliases. One provider is matched on its merchant-category code instead.
- Tags found or created during import belong to the family.
- Deleting a category leaves its transactions uncategorised and detaches its subcategories. Deleting a tag can move its taggings to a replacement tag.

#### Edge cases
- The matcher never creates categories and never overwrites a locked value.
- SimpleFIN accounts have no matcher.
- The "recently used" category list changes only on manual picks, never on matcher, rule or import assignments.

#### Acceptance criteria
- Given a matcher-enabled account and a family category "Restaurants", when a transaction arrives with the bank category "Restaurant", then it is categorised "Restaurants" through the alias match.
- Given the same transaction already has a category the user locked, when it syncs again, then the locked category is kept.
- Given an import with the category value "Home:Repairs" and no such categories, when it is mapped to "Add as new", then a parent "Home" and a child "Repairs" in the parent's colour are created.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - The shared global Category tree is provisioned by CategorySeeder and migrations. CategoryGroupProvisioner assigns the groups.
  - CategorySource provenance (isOverwritableByRule) keeps automation off Manual categories.
  - Deleting a category leaves its transactions uncategorised (category_id set null on delete).
  - PropagateTransactionCategory copies a category change to sibling transactions of the same PlannedTransaction, and to the plan itself.
  - RememberBnplCategory copies a BNPL plan's category onto its order and records a category-set event on it.
  - TransferLinker gives an uncategorised transfer leg its partner's Transfer category.
  - MonthEndBalanceRuleProvisioner gives each new user, via CreateNewUser, a ready-made Balance rule.
- **Gap:** The Redbark/SimpleFIN bank category and merchant category code are stored only in the transaction's enrichment data. They are used as hints by categories:jev-eval and ResolveMerchantBrandJob, but never mapped to a category. CategorySource::Feed has no writer. CSV import has no category column, and user tags do not exist.
- **Build it like:**
  - In SyncRedbarkFeedJob::applyRow, when building the values, map the feed category to a Category by full path and aliases, resolved the way CategorySeedRules resolves paths. Write CategorySource::Feed only when `category_id` is empty. This covers the update and settled-pending paths, which do not go through TransactionIngestor.
  - Add a per-account matcher toggle on Account or RedbarkAccount (C5) behind a budget-config flag (C6).
  - Add a category column to CsvColumnMapper. It must not create global categories (C2).
  - Migrations must work on MariaDB and SQLite (C8). Pest tests use the Redbark/SimpleFIN fixtures (C7).

## Statements, imports and provider sync

### F31. Statement Vault

#### What the user can do
- Upload one or many PDF, CSV or XLSX statement files in one go, either into an unmatched inbox or directly onto an account.
- Browse the inbox of unmatched and rejected statements and the list of linked statements. Both are paginated, with a total of storage used.
- Edit a statement's details: institution hint, account-name hint, last-four hint, period start and end, opening and closing balance, and currency.
- Accept the suggested account, link to a chosen account, reject the suggestion, or unlink a statement back to the inbox.
- Download the original file, or delete the statement.
- Open the "Statements" tab of any account type. It shows that account's statements and a per-month coverage grid for one calendar year at a time, with a year selector.
- The AI assistant can list statements, fetch one, report coverage and archive an uploaded file. It can only propose a match; linking stays a human action.
- Uploading a statement never creates imports or transactions.

#### Data relationships
- A family has many statements. Each statement optionally belongs to one account, and separately to one suggested account. Both must be in the same family.
- A statement has many PDF imports (F32). Deleting a statement that still has PDF imports is blocked. Deleting a PDF import keeps the statement.
- The original file is attached to the statement and purged when the statement is deleted.
- Review status (unmatched, linked, rejected) follows the account. Having an account makes it linked. Having none makes it unmatched, unless it was rejected.
- Rejected statements stay in the inbox so they can be deleted.
- Vault statements are not added to the family document search index (F35).

#### Edge cases
- **Allowed files:**
  - Only pdf, csv and xlsx are accepted, and the extension must agree with the type sniffed from the file bytes.
  - Empty files, and PDFs without a PDF header, are rejected.
  - The maximum is 25 MB. An upload of unknown size is cut off once it passes the limit.
- **Duplicates:**
  - Identity is the file's content, unique per family. Re-uploading the same file is skipped and counted in a "duplicate statements were skipped" notice.
  - The same file in another family is allowed.
  - The other files in the batch still succeed, and per-file errors are joined into one alert.
- **What is read from the file on upload:**
  - PDFs: the first two pages give the period, opening and closing balance, currency and last four digits. English, German, French, Spanish and Dutch labels are understood.
  - Balances that don't add up, or that mix currencies, are dropped.
  - The filename is the fallback for period, last four and institution.
  - CSVs get their period from the date column. XLSX files use the filename only.
- **Account suggestion:**
  - It scores institution text, account-name hint, last four and same currency against visible accounts only. Account notes are excluded.
  - Below 0.35 confidence, or on a tie, there is no suggestion.
  - Saving details on a statement with no account re-runs matching, unless the statement was rejected.
- **Coverage month statuses:** not expected, missing, covered, duplicate, ambiguous and mismatched.
  - Duplicate: two or more linked statements cover the month and overlap each other. Two linked statements that each cover a different half of the month count as covered.
  - Ambiguous: only an unmatched statement suggested for this account covers the month.
  - Mismatched: a balance check failed.
  - A statement covers every month its period touches.
  - The expected range starts at the earliest of the account's first entry, first balance, first linked statement or first unmatched statement suggested for this account, and ends last month. With none of these it defaults to 12 months.
- **Balance checks:**
  - Opening balance, closing balance and period movement are checked against the ledger in the statement's currency.
  - Checks run only when the statement has an account and a period and the ledger has balances on those dates.
  - A difference over 0.01 is a mismatch. If no check can run, the status is "Not checked".
- **Permissions:**
  - Guests cannot open the vault.
  - A linked statement is visible to anyone who can see its account. Managing it needs owner or full-control permission on that account.
  - Statements linked to accounts the user can't see are hidden from the lists and the storage total.
- **Rejected input:** a period end before its start, invalid currency codes and account ids from another family.

#### Acceptance criteria
- Given a CSV statement already in the family, when it is uploaded again, then no new statement is created and a duplicate notice is shown.
- Given an unmatched upload whose institution hint matches exactly one visible account, when the upload finishes, then that account is shown as the suggestion with a confidence and the statement stays unlinked.
- Given two linked statements for one account that both cover 1–31 March, when the coverage grid is opened, then March shows "duplicate".
- Given an account whose first entry is in January and no statement for February, when the grid is opened in April, then February shows "missing" and the months before January show "not expected".
- Given a linked statement with a closing balance of 2,000 and a ledger balance of 1,900 on the period end, when the grid is opened, then that month shows "mismatched".
- Given a guest, when they open the vault, then access is denied.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - StatementReconciliation (one per account and calendar month, open or closed) and StatementReconciliationLine (kinds matched, statement only, feed only; resolutions linked, imported, ignored).
  - The ReconcileStatement Livewire component and StatementReconciler. They keep the uploaded CSV as the reconciliation's stored file and replace it on re-upload.
  - The Account statementDueForLastMonth scope, which feeds the Dashboard "statements due" list.
  - ImportBank prefills "Current balance" from the CSV's last balance column and writes it to the Account balance on confirm. This is the only path where a statement balance reaches the ledger, and it overwrites the balance rather than comparing it.
- **Gap:**
  - Can-I-Budget only reconciles one CSV per month for an account linked to Redbark. It ticks lines against bank-delivered transactions and then closes the month. The closing balance read from the CSV is shown but never compared with the ledger.
  - There is no vault: no PDF or XLSX storage, inbox, account suggestion, statement details, download, content-based duplicate check or coverage grid.
- **Build it like:**
  - A new Statement model, owned by the user, with:
    - a nullable account and suggested account;
    - a content hash unique per user;
    - a period;
    - opening and closing balances in cents.
  - The file is stored on the local disk like StatementReconciliation's stored file. StatementReconciliation can optionally point at a Statement.
  - The inbox and coverage grid are a new Livewire page.
  - Conventions C1, C2, C3, C4, C5, C7 and C8 apply.

### F32. Bank-statement PDF import

#### What the user can do
- Upload a PDF on the new-import screen. It is filed in the vault as an unlinked statement, or the existing statement is reused. AI extraction then runs in the background, and an email with next steps arrives.
- Review the extracted transactions, the statement summary and the detected document type.
- Choose the target account, or accept the statement's account, and publish.
- Open an import summary: lines found, already recorded, imported as new, and waiting to import.
- Revert the import.
- Ask the AI assistant to turn a bank-statement PDF import into a transaction import for a chosen account.

#### Data relationships
- A PDF import belongs to the family and optionally to one vault statement. A statement can have many PDF imports.
- Each published line creates one transaction on the chosen account. Each matched line marks one existing transaction as reconciled by that statement.
- Reverting deletes the created transactions and releases the reconciled marks. Deleting the import keeps the statement file.

#### Edge cases
- **Requirements:**
  - A configured AI provider that supports PDFs is required. Otherwise the import fails with "AI provider not configured" or "AI provider does not support PDF processing".
  - The maximum is 25 MB, a non-PDF is rejected, and guests cannot start an import.
- **Document types:**
  - The types are bank statement, credit card statement, investment statement, financial document, contract and other.
  - Only the first two produce transaction rows. The others complete after analysis and are just stored for search.
- **Matching against existing transactions:**
  - Lines match on amount and a date within 3 days. The name is ignored.
  - Each existing transaction can be claimed only once, and provider-synced transactions count as matches.
  - Matching is re-checked at publish in case a sync landed in between.
- **Publishing:**
  - New transactions are created already reconciled and locked against provider overwrite.
  - A statement whose lines are all already recorded finishes complete with nothing to import.
- **Changing the account:** this releases the old account's reconciliations and regenerates the rows. After publish it is refused ("revert it first").
- **Unreadable dates:** dates that can't be parsed are offered for import and flagged in review, not dropped.
- **Re-uploading the same PDF:**
  - The statement is reused, and so is any import that has not failed, as long as the account and date format are unchanged.
  - A duplicate of a statement the user can't access gives "This PDF is already stored as a statement you cannot access".
- **Stuck imports and search:**
  - An import idle for over an hour can be marked failed.
  - The PDF is also added to the family document store (F35).

#### Acceptance criteria
- Given a processed bank-statement import with 5 lines, 2 of which match existing transactions, when it is published, then 3 transactions are created, the 2 existing ones are marked reconciled, and the summary shows 5 found, 2 already recorded and 3 imported.
- Given two statement lines of 100 on the same day and one existing transaction of 100, when matching runs, then one line matches and the other is offered for import.
- Given a published import, when the user changes its account, then the change is refused until the import is reverted.
- Given no AI provider is configured, when a PDF is uploaded, then the import ends failed with the provider message.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:** StatementReconciler matching, CSV only.
- **Gap:**
  - There is no PDF reading or AI extraction of statements.
  - Matching a statement to the ledger exists only for CSV, in StatementReconciler. It also requires a matching description, while Sure ignores the name.
- **Build it like:**
  - Extraction runs in a new queued job that carries AttributeJobToUser. The AI call sits behind a budget-config flag and is faked with Http::fake in tests.
  - StatementReconciler cannot take the extracted lines as it stands:
    - it works from a StatementReconciliation, which is CSV only, one per account and calendar month, and for Redbark-linked accounts only;
    - it re-reads the stored CSV;
    - it considers only bank-feed or CSV transactions, so manual entries are never matched;
    - it needs equal description fingerprints, so a blank description never matches.
  - Reusing it needs a seam that accepts a list of ParsedTransactionDto, a name-blind match on amount and a date within 3 days that also covers manual transactions, and periods that are not calendar months. Otherwise PDF matching is built separately and produces the same matched, statement-only and feed-only lines.
  - Conventions C1, C2, C4, C6, C7 and C8 apply.

### F33. Import wizard (CSV and others)

#### What the user can do
- **What can be imported:**
  - Transactions, trades, accounts, categories, merchants or rules from CSV.
  - Exports from Mint, Actual Budget and YNAB.
  - Quicken QIF files and a full Sure export.
  - PDF statements (F32) and documents (F35).
  - OFX/QFX is not supported.
- **Upload:** upload a CSV file or paste its text, with a comma or semicolon delimiter and an optional account. A sample CSV can be downloaded.
- **Configure:**
  - Map the columns: date, amount, name, currency, category, tags, account and notes, plus quantity, ticker and price for trades.
  - Set the date format, number format, sign convention and rows to skip.
- **Apply template:** "Apply template" copies the settings of the account's previous completed import of the same type.
- **Clean:** edit cells in a table where invalid cells are red, and filter to error rows only.
- **Map values:** map the file's categories, tags and accounts to existing ones, create new ones, or leave them empty.
- **Publish and recover:** publish in the background, revert, retry a failed import, or mark a lost import failed.
- **QIF and full-export extras:**
  - QIF lets the user choose which categories and tags to bring in.
  - A full-export import shows a count per record type before publishing and a read-back check afterwards.

#### Data relationships
- An import belongs to the family, optionally to one account, and has many rows. Each row produces at most one transaction, trade, account, category, merchant or rule.
- Mappings link file values to existing categories, tags and accounts, or to new ones created at publish.
- Revert removes the transactions and accounts the import created. It also deletes existing transactions the import only matched as duplicates, because a match re-points that transaction to the import. Revert and delete need write access to every target account.

#### Edge cases
- **Limits:**
  - Transaction and trade imports are disabled until an account exists.
  - A CSV may be at most 10 MB and 10,000 rows ("exceeds the maximum row count of N").
  - A full-export import may be at most 10 MB and 100,000 rows; both limits are configurable.
  - Rows to skip must be fewer than the file's line count.
- **Headers and encoding:**
  - Headers match regardless of case and spaces, with common aliases. Two headers that normalise the same are an error.
  - The file's encoding is detected and converted to UTF-8.
- **Numbers, signs and dates:**
  - Number formats are 1,234.56, 1.234,56, 1 234,56 and 1,234. An unparseable number becomes blank and fails validation.
  - The sign comes from a convention, or from a type column with a required inflow value.
  - The date format is detected from samples, and only plausible formats are offered.
- **Row validity:**
  - A row is invalid if its date doesn't match the format or falls after today, its amount isn't numeric, or its currency code is invalid.
  - Nothing publishes until every row and every mapping is valid.
  - A row whose account is still unmapped at publish fails the whole import, and the message names the row.
- **Duplicates:**
  - A row matching an existing transaction (same account, amount, currency, date and name) updates that transaction's category, tags and notes instead of creating a new one. Only manual or imported transactions are matched; a provider-synced twin is never matched, so the row is created alongside it.
  - Identical rows within one file are all imported.
- **After publishing:** imported transactions are locked against provider overwrite, and publishing triggers a family sync.
- **Revert and stuck imports:**
  - A revert returns a standard import to pending with its rows, ready to re-publish. A failed revert offers a retry.
  - A publish redelivered while the import is complete or reverting is ignored. Imports stuck for six hours are reclaimed automatically.
- **Guests** cannot import categories, merchants, rules or full exports, and cannot create new categories or tags.

#### Acceptance criteria
- Given a CSV with the date 15/03/2024 and the format DD/MM/YYYY, when it is configured, then the rows are valid. With MM/DD/YYYY those cells turn red.
- Given an existing transaction of 100 on 1 March named "Coffee", and a CSV row with the same values and the category "Food", when it is published, then the existing transaction gets "Food" and no new transaction is created.
- Given a completed transaction import of 10 rows, when it is reverted, then the 10 transactions are removed and the import is pending with its 10 rows.
- Given a row whose account name is not mapped, when it is published, then the import fails and names that row.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - The ImportBank three-step wizard:
    1. Upload the file and pick or create an account.
    2. Map date, description, signed amount or debit/credit, and balance, with a preview, PreviewSummary and the balance input, then confirm.
    3. See the result while the import's status is polled.
  - CsvParserService and CsvColumnMapper. The mapper suggests columns from the headers and saves the mapping per account in the Account column_mapping.
  - BankImport with BankImportStatus.
  - ImportCsvTransactionsJob: it removes duplicates by a hash of date, amount and description per account, including soft-deleted rows, and keeps errors per row. RunTransactionAnalysisJob follows.
- **Gap:**
  - Only transaction CSVs can be imported: no trades, accounts, categories, rules, QIF or other apps' exports.
  - There is no editable clean step, no category or tag mapping, and no revert.
  - The user can't choose a date or number format. Dates are tried in a fixed list, day-first before month-first, and commas are treated as thousands separators.
  - Identical rows in one file collapse into one transaction.
  - CSV import into a Redbark-linked account is refused.
- **Build it like:**
  - Extend ImportBank and BankImport. Add a bank-import reference on Transaction that is set only when the import creates a row. Rows the import restores from soft-deleted are recorded separately, for example with a restored-by import reference.
  - A revert soft-deletes exactly the rows that import created or restored. Rows it only matched or overwrote are left alone, so unlike Sure a revert never deletes transactions an earlier import or the user created.
  - Add a date- and number-format choice to CsvParserService.
  - New import types become further steps on the same component.
  - Conventions C1, C2, C3, C4, C5, C7 and C8 apply.

### F34. Provider sync (bank/crypto connections)

#### What the user can do
- **Providers:** connect one of 26 provider types in two families:
  - 14 bank, card and wallet feeds. These include an Apple Wallet publisher, Redbark and SimpleFIN.
  - 12 brokerage and crypto connections.
  - Depending on the provider, connecting uses an OAuth or link flow, a pasted setup token, or an API key.
- **Account setup:** map each provider account to a new account with a chosen type and subtype. Some providers also allow linking to an existing manual account. Provider accounts that are still unlinked show "need setup".
- **Sync:** sync a connection manually, or cancel a sync in progress.
- **Connections summary:** status, whether credentials are configured, linked and unlinked counts, last sync, and text such as "N synced, M need setup" or "No accounts found".
- **Credentials:** replace expired credentials, for example by pasting a new token, while keeping the account links. Set an optional sync start date where the provider supports it.
- **Cleanup:** delete, or move to another account, provider accounts that no longer exist upstream.
- **Disconnect** a connection.

#### Data relationships
- A family has many connections, and a connection has many provider accounts.
- Each provider account links to at most one family account. An account links to at most one provider account per provider.
- A family sync fans out to every active connection and all manual accounts. Afterwards it auto-matches transfers and applies rules.
- Each sync is recorded as pending, syncing, completed, failed or stale.
- Disconnecting detaches the links first. The accounts and their transactions remain in the family, unlinked.

#### Edge cases
- **Overlapping and stale syncs:** a sync request within 5 minutes of a visible sync joins it, possibly widening its date window, instead of starting a second. Syncs unresolved after 24 hours are marked stale by an hourly sweep.
- **Triggers:**
  - Manual.
  - Once a day on login, if auto-sync is on.
  - A nightly sync, on by default at 02:22 UTC. Admins can turn it off or change the time and timezone.
  - One rate-limited crypto aggregator syncs hourly.
- **Cancelling** marks waiting child syncs stale at once. Running ones finish, and the whole sync resolves to stale.
- **Pending transactions:**
  - Pending transactions from 10 providers are replaced by their posted version, matched by the provider's link id or by amount and date.
  - If the user had edited the pending one, their edits are kept and only the pending flag clears.
  - Pending entries older than 8 days with no posted version are excluded.
- **Connection status:** a connection is either good or requires update. Expired or invalid credentials or consent set requires update and fail the sync. One provider warns when the last sync is over 3 days old.
- **Duplicate institutions:** connecting an institution that is already connected shows a warning and needs confirmation.
- **Disconnect:**
  - The connection disappears at once and is deleted in the background.
  - Errors from the provider's own removal are logged, and deletion still goes ahead.
  - If the background deletion can't be scheduled, the connection is restored.

#### Acceptance criteria
- Given a sync is already running for a connection, when the user clicks sync, then no second sync starts.
- Given a pending transaction of 60, when the posted 60 arrives, then there is one transaction, not two.
- Given a connection whose consent has expired, when a sync runs, then it fails and the connection shows requires update.
- Given a linked account with 100 transactions, when its connection is disconnected, then the account and its 100 transactions remain.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Models:
    - RedbarkFeed: one per user, with RedbarkTransport (rest or simplefin) and RedbarkFeedStatus (good or requires update).
    - RedbarkAccount.
    - RedbarkSyncLog, with RefreshTrigger and RefreshStatus.
  - Connecting and setup:
    - ConnectBank connects with an API key or with a SimpleFIN setup token through SimpleFinClaimer.
    - RedbarkAccountSetup lets the user create an account, link an existing one, or skip.
  - The providers settings page: update key, Sync now (blocked while a sync is open), disconnect, and the last 10 syncs with 3-second polling.
  - Sync:
    - SyncRedbarkFeedJob is unique for 900 seconds and collects errors per phase. It sets requires update after 3 consecutive authentication failures, and claims settled pending rows within 8 days.
    - RedbarkTransactionMatcher adopts existing CSV history so it isn't imported twice.
  - Scheduled commands:
    - SyncRedbarkFeedsCommand runs every 6 hours for feeds in good status only.
    - ExpireRedbarkHoldsCommand removes holds that never settle.
  - Disconnect turns linked accounts into manual accounts and keeps their transactions.
- **Gap:**
  - There is a single provider (Redbark, over REST or SimpleFIN) and one feed per user, with no brokerage or crypto connections.
  - There is no sync cancel, no daily sync on login and no admin-set nightly time.
  - Requests made while a sync is open reuse its log and are dropped, but the open sync's date window is never widened.
  - Unlinked accounts that vanished upstream are pruned on each sync, but linked ones can't be deleted or moved to another account.
- **Build it like:**
  - Extend RedbarkTransport and RedbarkClientFactory with further transports on the same RedbarkFeed and RedbarkAccount tables rather than new feed tables.
  - The unique user_id on RedbarkFeed gives way to a unique user_id and transport pair, and User::redbarkFeed becomes a HasMany. The feed lookups and the one-feed checks in ConnectBank, the providers page, Dashboard and onboarding become per transport.
  - New provider clients sit behind budget-config flags.
  - Conventions C2, C4, C6, C7 and C8 apply.

### F35. Family document store and file search

#### What the user can do
- Upload a non-PDF document with "Import document" on the new-import screen. PDFs go through the PDF import (F32) and are added to the store from there.
- Ask the AI assistant questions answered from the stored files. It returns 1–20 excerpts (10 by default), each with the filename and a relevance score.
- Accepted types are office documents, spreadsheets, text and markdown, source code, images and archives. The active vector store sets the exact list.
- There is no screen to browse or delete stored documents.

#### Data relationships
- A family has one vector store, created on the first upload, and many documents.
- Each document records its filename, type, size and a status: pending, processing, ready or error.
- PDFs analysed through the PDF import are indexed. Vault statements (F31) and statements archived through the assistant are not.
- Document details, without file bytes, appear in the data export manifest (F36).

#### Edge cases
- The maximum is 25 MB. An unsupported type gives "Invalid document file type for the active vector store".
- With no vector store configured, uploads are refused ("No vector store is configured"), and the assistant says no provider is configured.
- With no documents yet, the assistant reports "No documents have been uploaded to the family document store yet". With no hits it reports "No matching documents found".
- There are three store back-ends: one hosted AI vector store and two self-hosted. The default depends on which AI provider is configured.

#### Acceptance criteria
- Given no vector store is configured, when a document is uploaded, then the user is told none is configured.
- Given an uploaded PDF, when the assistant searches for a phrase in it, then an excerpt with that filename is returned.
- Given a statement uploaded straight into the vault, when the assistant searches its contents, then nothing is found.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:**
  - There is no document upload, store or search.
  - The nearest feature, GmailService with TransactionEmail, searches the user's Gmail for one transaction's receipt and links it.
  - There is no AI assistant to query documents (F41).
- **Build it like:**
  - A new user-owned Document model with filename, type, size and a status enum. Files go on the local disk like BankImport's stored file.
  - Indexing runs in a queued job that carries AttributeJobToUser, behind a budget-config flag. The vector API is faked with Http::fake in tests.
  - Conventions C2, C3, C4, C5, C6 and C7 apply.

### F36. Data export

#### What the user can do
- Admins start a full export, which runs in the background. The exports list refreshes every 3 seconds while one is in progress.
- Download a completed export or delete one. An export idle for over an hour can be marked failed ("mark as failed").
- The same operations exist in the API, with streamed downloads.
- On the reports page, export a transaction breakdown as CSV for a date range with sort options, and open instructions for pulling that CSV into Google Sheets.

#### Data relationships
- A family has many exports. Each has a status (pending, processing, completed or failed) and one zip file.
- **What the zip holds:**
  - CSVs of accounts, transactions, trades, categories, merchants and rules.
  - One NDJSON file with all records, which the "Import from Sure" flow (F33) reads.
  - A manifest of attachments and documents with checksum and size, but no file bytes.
- Split transactions export their parts, not the parent.

#### Edge cases
- Non-admins are redirected with an access-denied message.
- Stuck exports fail automatically after 2 hours. A redelivered job never restarts a completed or failed export.
- The merchants and categories CSVs use the importer's headers, so they can be re-imported unchanged.
- When an inactive trial family is destroyed, it is archived as an export first. The archive is served by a secret link that needs no sign-in and returns "gone" once it expires.

#### Acceptance criteria
- Given a non-admin, when they open exports, then they are redirected with an access-denied message.
- Given a completed export, when it is downloaded, then it is a zip with the CSVs, the NDJSON file and a manifest, and no attachment bytes.
- Given a split transaction of 100 with parts of 60 and 40, when it is exported, then the transactions CSV has rows of 60 and 40 and none of 100.
- Given an export stuck for over an hour, when the admin marks it failed, then a new export can be started.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:** Can-I-Budget has no data export of any kind: no transaction or report CSV download and no backup archive.
- **Build it like:**
  - A new user-owned DataExport model with a status enum and a stored path.
  - A queued export job that carries AttributeJobToUser and writes with League CSV, which CsvParserService already uses, to the local disk.
  - A named, user-attributed download route, and a Livewire page for the list.
  - Conventions C2, C3, C4, C5, C7 and C8 apply.

## Reporting and investments

### F37. Dashboard widgets and period selector

#### What the user can do
- The home page is a grid of widgets:
  - cash-flow Sankey
  - money-flow bars
  - spending trend
  - outflows donut
  - investment summary
  - net-worth chart
  - balance sheet
  - insights feed (preview users only)
- In customise mode the user can drag widgets to reorder them, collapse or expand them, and hide them and add them back from a "hidden" list. They can set some widgets to half or full width and give chart widgets a compact, auto or tall height. A two-column layout is a separate appearance preference.
- The period selector offers 1D, WTD, 7D, MTD, LM, 30D, 90D, YTD, 365D, 5Y, 10Y, All, or a custom start and end. The chosen period is saved as the user's default. It drives the Sankey, outflows, net-worth and investment widgets.
- Money flow shows six monthly income and expense bars. It has its own month picker and an account filter, and marks the month in progress as partial.
- Spending trend plots the selected month's cumulative daily spending against the previous month. It can be filtered by category, and picking a parent includes its children.
- The net-worth chart covers the selected period. The balance sheet lists assets and liabilities by account group, sorted by the user's account-order preference. Groups can be set to start expanded.
- The investment summary is offered only when the family has an investment account.

#### Data relationships
- Dashboard layout (order, hidden, collapsed, width, height, Sankey grouping) belongs to the user, not the family. Two members of one family can have different layouts.
- Every widget reads the family's accounts, narrowed to the accounts the viewer can access. Revoking a share removes that account from the viewer's widgets and balance sheet.
- The family's custom month-start day (1–28) changes the ranges of "current month" and "last month". Their period keys stay the same.

#### Edge cases
- An unknown period key, or a custom pair that is invalid or backwards, falls back to the last 30 days.
- "All" starts one month before the first transaction or trade:
  - A stray old valuation does not stretch it.
  - A family with only valuations starts at its oldest entry.
  - A computed start of today or later becomes a 5-year range.
- Charts use monthly points when the range is over 5 years, weekly points when it is over 1 year, and daily points otherwise.
- A money-flow month in the future is clamped to the current month. The current month is capped at today.
- The spending-trend header delta compares the same number of elapsed days. When the previous month is longer, its extra days fold into the last point.
- Net-worth velocity and momentum are withheld when balance history does not cover the whole window. An account opened mid-window does not count as growth from zero.
- Hidden widgets run no queries, and widgets with no data are not offered in the hidden list. A widget missing from a saved order is added at the end, except the insights feed, which goes first.
- Users on the guest intro layout are sent to chat instead of the dashboard.

#### Acceptance criteria
- Given a saved default period key that no longer exists, when the dashboard loads, then it shows the last 30 days.
- Given a family month-start day of 15 and today is 10 March, when the user picks MTD, then the period starts on 15 February.
- Given a custom range of 1 January 2024 to 1 January 2026, when the net-worth chart renders, then it uses weekly points.
- Given the user hides spending trend and reloads, then the other widgets keep their saved order and spending trend appears in the hidden list.
- Given a family with no investment accounts, when the dashboard loads, then no investment summary is shown or offered.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Dashboard is lazy-loaded and refreshes on `transaction-saved`. It shows: buffer until next pay, days until pay, total owed, total available, total needed until payday, Budget spend this pay cycle, the next three PlannedTransaction occurrences, the last 7 days of spending with a 14-day sparkline and payday marker, statements due, pending transfers (TransferReviewQueue) and FirstImportSummary.
  - Two embedded components: Dashboard\PayCycleCalendar, and Dashboard\MonthlyProjection (a 12-month projection of the primary account's balance from MonthlyProjectionService and BalanceProjection, flagging the first date the balance goes negative).
- **Gap:** The layout is fixed and the user cannot customise it. There is no dashboard period selector, no net-worth chart, no balance sheet of assets and liabilities, and no spending-trend or six-month money-flow widget.
- **Build it like:**
  - Extend Dashboard with a per-user layout stored in the F44 preference store.
  - Extend TransactionPeriod with the missing cases (1D, WTD, last month, 30D, YTD, 5Y and 10Y), each with a concrete end date, and drive the period selector with it.
  - Net worth is the signed sum of the balances of active accounts, since debt is already stored negative; a series needs F2 balance history. AccountClass::isAsset is used only to group the balance sheet. Account::amountOwed and User::totalOwed are aligned with AccountClass::isAsset so there is one liability definition, and Mortgage counts as debt.
  - Keep each widget a lazy Livewire child component (C3), and cover them with Pest Feature tests on SQLite (C7, C8).

### F38. Income statement, outflows and cash-flow Sankey

#### What the user can do
- See income and expense totals by category for the selected period, with subcategories rolled into their parents and amounts in the family currency.
- See the outflows donut: net expense per category, sorted largest first with percentage shares. Slices are clickable except the "other investments" slice.
- See the cash-flow Sankey: income categories flow into a central Cash Flow node and expense categories flow out of it. A Surplus node appears when net income is positive.
- Preview users get a second Sankey that can be grouped by category (the default) or by account. The chosen grouping is saved per user, and the same graph is served through the API.

#### Data relationships
- Totals are drawn from the family's entries on active or draft accounts, scoped to the viewer's "included in finances" accounts. Cross-currency amounts are converted at exchange rates.
- Each Sankey node is a category: parent categories sit at the first level, and subcategories are netted against their refunds.

#### Edge cases
- These are left out of all three views:
  - pending transactions
  - funds movements, credit-card payments and one-time entries
  - accounts flagged "exclude from reports"
  - tax-advantaged accounts (cash-flow views)
- Loan payments and investment contributions count as expense. Trades never count as income or expense.
- A subcategory whose net runs opposite to its parent's appears on the other side of the Sankey.
- When expenses are equal to or greater than income there is no Surplus node. The original Sankey draws no Deficit node; the grouped preview graph draws a Deficit node when expenses exceed income.
- The grouped graph rejects a non-ISO date, a start after the end, or an unknown grouping with a 422 error. Its response is never cached.

#### Acceptance criteria
- Given income of 2,000 and expenses of 1,500 in the period, when the Sankey renders, then it has a Surplus node of 500.
- Given income of 1,000 and expenses of 1,200, when the Sankey renders, then it has no Surplus node.
- Given a 60 grocery purchase and a 10 grocery refund, then groceries show 50 in the donut and the Sankey.
- Given a pending transaction of 100, then it is not in any total.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:** CalendarView's month flow section has three slides: "Plan vs actual", "Cash flow" and "Outflows".
  - **Cash flow:** CashFlowLoader and CashFlowGraphBuilder build a Sankey for the month. It runs income groups into a Cash flow hub and on to expense groups, with a Surplus or Deficit node. Figures are netted by category so refunds reduce their category. Parent nodes can be drilled into their categories, and a parent can sit on both sides.
  - **Outflows:** a donut of the same expense groups with percentages. Each row links to the month's transactions filtered by group, except the Ungrouped row, which has no link.
  - **Plan vs actual:** MonthFlowLoader compares PlannedTransaction totals with actuals.
  - **Shared rules:** in Cash flow and Outflows, transfers to or from an untracked account count; Plan vs actual leaves transfers out on both the planned and actual sides. Untracked-account rows are excluded through CategoryAttribution, and split lines are counted per allocation.
- **Gap:** The views work only per calendar month and group by CategoryGroup rather than by category, with no grouping by account and no saved preference. Pending transactions are not excluded: neither CategoryAttribution nor ReportAggregator filters on status, and pending Redbark rows exist. There is no per-account "exclude from reports" flag, and there is no API output.
- **Build it like:**
  - Give CashFlowLoader a date range from TransactionPeriod, and add an account grouping alongside the group grouping in the same FlowParent/FlowChild shape.
  - Store the grouping in the F44 preference store.
  - Keep the reads in Livewire computed properties (C3) and cover them with Pest Feature tests on SQLite (C7, C8).

### F39. Reports page

#### What the user can do
- Choose a monthly, quarterly, year-to-date, last-6-months or custom period, step to the previous or next period, or pick one.
- Summary cards show income, expenses and net savings, each with percent change against the equal-length period just before. A budget-performance card shows only for the current calendar month.
- The page has five sections: net worth, trends and insights, investment performance, investment flows, and activity breakdown. Sections can be collapsed and reordered, and the order is saved per user.
- **Net worth:** current net worth, assets and liabilities by group, the change over the period, and a series per group.
- **Trends:** a month-by-month table with savings rate and averages, plus a comparison of per-transaction spending on weekdays and weekends.
- **Activity breakdown:** spending by parent category and subcategory, filterable by category, account, tag and amount range, sortable by amount or count, and paginated.
- **Investment performance:** portfolio value, total and period return, contributions and withdrawals, and the top 5 holdings. Gains are split by tax treatment, each with unrealised and realised gains and the sell trades behind them.
- **Print view:** a print-formatted version of the report.
- **CSV export:** a monthly category breakdown with income and expense sections and totals. It can be pulled by session or by API key, and a Google Sheets helper builds an import URL with a warning that the URL contains the key.

#### Data relationships
- The report reads the family's accounts, entries, budgets and holdings, scoped to the viewer.
- The section order belongs to the user.

#### Edge cases
- If the end date is before the start date, the dates are swapped and an alert is shown. The CSV export swaps them silently.
- Investment flows show only when the period has contributions or withdrawals.
- Realised gain is withheld when no exchange rate exists for that day; the rate is never assumed to be 1.
- Transfers and sweeps out of an investment account do not count as sales.

#### Acceptance criteria
- Given March expenses of 2,000 and expenses of 1,600 in the 31 days before 1 March (29 January–28 February), when the user views March, then expenses show a change of +25%.
- Given a start of 31 March and an end of 1 March, when the page loads, then it covers 1–31 March and alerts that the range was invalid.
- Given a CSV export request with an expired API key, then the response is 401. Given a key without a read scope, then it is 403.
- Given a period with no contributions or withdrawals, then the investment flows section is absent.
- Given the user views last month, then no budget-performance card is shown.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:** CategoryReport is the reports page, backed by ReportAggregator.
  - **Periods:** TransactionPeriod offers 7 days, this month, 3 months, 6 months, 1 year, pay cycle, all time and a custom range. A backwards custom range is swapped silently.
  - **Modes:** "real" (actuals through CategoryAttribution) and "plan" (PlannedTransaction occurrences).
  - **Summary:** Income, Expenses and Net cards with per-month averages and percent of income.
  - **Charts:** a monthly income/expense/net chart and expense and income treemaps.
  - **Category buckets:** rolled up to the root category, with expandable children and a top-5 toggle.
- **Gap:** These are missing:
  - previous-period comparison and budget performance
  - net-worth, trends/savings-rate and investment sections
  - section reordering
  - account, tag and amount filters, and sorting
  - print view and CSV/API export
  - netting of refunds against expenses: the report sums absolute amounts per direction
- **Build it like:**
  - Extend CategoryReport and ReportAggregator. A second atoms call over the prior equal-length range gives the period-over-period change.
  - Add filters as Url properties (C3), and store the section order in the F44 preference store.
  - Export through an audited Livewire download action (C3), or a named GET route that inherits AttributeRequestToUser from the web group. GET routes are not in UserActionInventoryTest; only a POST export route would join its write-route list (C4).
  - Money stays integer cents (C1). Pest Feature tests run on SQLite (C7, C8).

### F40. Holdings, trades and securities

#### What the user can do
- See the holdings on each investment or crypto account, with quantity, average cost, weight and return. A detail view adds trade history, market price, book value, market value and the time of the last price update.
- Enter a cost basis as a total for the position; it is stored per share, and zero is valid for gifts, inheritances and airdrops. A manual entry locks the value, and unlocking it lets only a value calculated from trades replace it.
- Record activity:
  - Buy, sell, sweep in, sweep out, reinvestment and dividend need a security.
  - Interest and fee fall back to a cash security.
  - Deposit and withdrawal optionally take a counter-account, which creates a transfer; without one they are plain transactions.
- Remap a holding to a different security found by ticker search, or reset it to the provider's original.
- Delete a holding, which also deletes its trades.
- Refresh prices for the last 31 days by hand.
- View an investment statement: portfolio value, cash, holdings value, allocation, top holdings, day change, unrealised gains, period return, all-time contributions, dividends and interest.
- Ticker search queries all enabled securities providers at once. Admins enable or disable providers.

#### Data relationships
- An account has many holdings. Each holding points to one security, carries its own currency, and may keep a link to its original provider security after a remap.
- A security has many prices and splits, which are deleted with it. Its trades are kept and lose their security link.
- A ticker is unique per exchange, case-insensitively, and the security's provider must be one of the known providers.
- A trade is an entry on an account. It is always excluded from budgets, and the user can also exclude it from analytics.

#### Edge cases
- Sells and sweep-outs store a negative quantity. A trade's amount is quantity × price + fee, and dividends and interest are stored as inflows.
- Cost basis comes from, in order of priority: a manual entry, then a calculation from trades, then the provider. It shows "--" when unknown. If any shares arrived through an internal movement, the whole position's cost is unknown and is never guessed.
- Realised gain is computed for sells only, never for transfer or sweep activity. It is withheld when no exchange rate exists for that day.
- A remap merges holdings that collide on the same day and currency: quantities and amounts are summed and the cost is a weighted average. Remapped holdings are locked against provider overwrite. A warning explains that the remap also moves any genuine trades already on the target security.
- Deleting a holding is refused with an alert when the account's provider would just recreate it. Holdings on manual accounts can always be deleted.
- A price refresh rejects offline securities with an alert. A provider that returns zero prices shows an inline error.
- Disabling a provider takes its securities offline. Re-enabling it restores only those securities, not ones taken offline by health checks.
- Changing a trade's quantity or price re-derives its name and activity label. Manual entries are locked against sync overwrites and can be unlocked.

#### Acceptance criteria
- Given a manual cost basis of 100 for 10 shares, then the stored cost per share is 10 and the value is locked.
- Given a locked cost basis, when a provider sync sends a new one, then the value is unchanged.
- Given 10 shares at an average cost of 15, when the user sells 10 at 20 with a fee of 5, then the realised gain is 45.
- Given 10 shares with a cost of 10 and a current price of 12, then the unrealised gain is 20.
- Given an account linked to a provider that recreates holdings, when the user deletes a holding, then an alert says it cannot be deleted and the holding remains.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none. AccountClass has an Investment case, but it only labels an account type, drives BudgetTagResolver's Savings tag, and counts Investment balances as spendable in the Dashboard total available. A search found no holding, security, trade, cost-basis or price models, migrations or config.
- **Gap:** There is no record of positions, securities, trades, prices or gains. An investment account holds only a balance and transactions.
- **Build it like:**
  - **Models:** a global Security model, scoped like Category (C2), with SecurityPrice children. User-scoped Holding and Trade models are reached through Account (C2, C5).
  - **Storage:** money in integer cents (C1), and quantity in a decimal column that works on both SQLite and MariaDB (C8).
  - **Price sync:** a scheduled command dispatches one queued job per user for the securities that user holds. Each job carries AttributeJobToUser with audited false, upserts the global Security and SecurityPrice rows, and is added to UserActionInventoryTest's job list with its audited flag (C4). The provider is faked with Http::fake in tests (C7).
  - **Feature flag:** the whole feature sits behind a budget-config flag that is off by default (C6).

## Platform, access and settings

### F41. AI assistant chat

#### What the user can do
- Start chats with an AI assistant, send messages, retry the last message, and rename or delete chats. Each user sees only their own chats, and the last chat they viewed reopens.
- Consent before any AI use. AI stays off until the user enables it. A chat page shows an inline consent screen, and any attempt to write redirects to the consent screen.
- Watch replies stream in behind a "Thinking…" bubble. When something fails, the error says rate-limited, temporarily unavailable, misconfigured or a generic error, and a Retry button appears.
- Ask questions the assistant answers from family data: transactions, recurring series, accounts, holdings, balance sheet and net-worth history, income statement, budget, tags, categories, merchants, rules, and a search over family files.
- Ask the assistant to change data:
  - create, update and delete transactions
  - create and update tags and categories
  - create goals and set an opening balance
  - import a bank statement
  - update or unmatch transfers
  - update the budget
  - create, update, preview and apply rules
- Family admins pick the assistant mode: built-in, external agent, or MCP-only (no in-app chat). They can also override the system prompt for the family.
- Admins see an LLM usage and cost page with a date filter, broken down per operation and per model.
- Guests get an introductory conversation with no tools that asks about the household.

#### Data relationships
- A user has many chats, and a chat has many messages. Deleting a user deletes their chats, and deleting a chat deletes its messages.
- Chats are private to the user who created them and are never shared across the family.
- LLM usage records belong to the family and are deleted with it. Mode, provider preference, default model and prompt override are family settings.
- Every assistant tool acts through the requesting user's own account permissions within the family.

#### Edge cases
- Hosted installs always allow AI. A self-hosted install needs an OpenAI or Anthropic key, or a configured external assistant, which can be limited to an email allowlist.
- The browser stops waiting after 90 seconds by default, never less than 30, and reports the reply as undelivered. The server refuses that report until the reply is older than its own minimum age.
- A reply that fails partway is marked failed and left out of the history sent with later messages.
- Writes on an account the user can only read return "not found". Deleting a split child transaction is refused.
- The prompt carries today's date, the family currency and date format, and the accounts and categories. Those lists shrink to counts when they are long or the model's context window is small.
- Holdings come back 50 per page, net-worth history is capped at 400 points, and the income statement at 36 monthly buckets.
- Guests cannot use the tag and category write tools. The assistant never names its tools to the user.
- Statement-vault, valuation, insights and bills tools are offered only to users who have preview features enabled.

#### Acceptance criteria
- Given a user who has not consented, when they post a message, then they are redirected to the consent screen and no message is created.
- Given a family in MCP-only mode, when a member opens the app, then there is no in-app chat and MCP still lists the tools.
- Given a member with read-only access to an account, when the assistant tries to recategorise one of its transactions, then the tool answers "not found" and the category does not change.
- Given a self-hosted install with no OpenAI or Anthropic key and no external assistant, when a user opens chat, then the assistant is unavailable.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:** There is no conversational assistant, chat history, AI consent or LLM cost tracking. The only AI integration is the Jev categoriser (TypeSafeService, behind `budget.jev_categorisation`), which suggests payee categories and does not chat.
- **Build it like:**
  - Data and page: new user-owned Chat and ChatMessage models (C2, C5), and a chat page in the auth+verified group with every public action audited (C3).
  - Background work: replies produced by a queued job that carries AttributeJobToUser and is added to the UserActionInventoryTest job list with its audited flag (C4).
  - Flag and tests: the feature behind a new `budget.*` flag (C6), and LLM HTTP calls faked with Http::fake (C7).
  - Tools: they should call existing services (ReportAggregator, RuleTester, UserRuleApplier, TransferLinker) and not reimplement their logic.

### F42. MCP server and REST API

#### What the user can do
- Connect an external AI client to the family's data over MCP (JSON-RPC: initialise, list tools, call a tool). It gets the same tools as the in-app assistant, filtered by the user's preview and guest rules.
- Authorise an MCP client with OAuth, which supports discovery and dynamic client registration. A self-hoster can instead use a static server token mapped to one user.
- Settings show the MCP address and the user's connected tokens, and each token can be revoked.
- Create API keys with exactly one scope, read or read-write. A key is shown once, its name must be unique among the user's active keys, and it can be revoked or set to expire.
- Read over REST:
  - accounts, balances, budgets and budget categories
  - merchants, rules and rule runs
  - securities, prices and holdings
  - transfers and rejected transfers
  - balance sheet, family settings, usage, syncs and provider connections
- Write over REST:
  - create categories and merchants
  - full create, read, update and delete on tags, transactions (including splits), trades and recurring transactions
  - create and update valuations
  - create imports, including chunked import sessions, and family exports
  - manage chats and messages
  - trigger a sync, and reset or delete the user
- Rules are read-only over REST. Over MCP they can be listed, created, updated, previewed and applied.
- The mobile app has its own auth flow: sign up, log in with device info, refresh, single sign-on exchange or link, and enabling AI.
- The cash-flow and insights endpoints are behind Sure's preview-features flag.

#### Data relationships
- A user has many API keys and OAuth tokens. Deleting the user destroys their keys, and deactivating the user revokes their tokens, grants and keys.
- Every call is scoped to the token owner's family and to the accounts that owner can access.
- An MCP session is tied to one user and lasts one day.
- A rule-apply token is bound to one rule's definition and to its match count.

#### Edge cases
- MCP rejects read-only tokens and tokens whose owner is inactive. A missing, invalid or expired token gets 401 with a pointer to the authorisation metadata.
- A tool the user may not use is answered as "Unknown tool", and tool errors are not detailed to the client.
- JSON-RPC notifications get an empty 204, and a tool call sent as a notification does not run. Protocol versions other than 2025-03-26 and 2025-06-18 fall back to the default.
- On managed (hosted) installs each API key is limited to 100 requests per hour, with rate-limit headers and 429 beyond that. Self-hosted installs have no limit.
- A rule created through MCP is saved inactive.
  - Renaming a rule keeps it active. Changing its conditions, actions or effective date deactivates it.
  - The apply token expires after 30 minutes, and a rule with an AI action cannot be applied this way.
- The recurring-transactions API writes only the statuses active and inactive, and creating a duplicate series returns 409.
- Guests cannot write family configuration. Push-subscription endpoints exist on every install but refuse with 403 unless the install is hosted.

#### Acceptance criteria
- Given a read-only API key, when it calls a write endpoint, then the response is 403 "insufficient scope".
- Given a managed-mode key that has made 100 requests this hour, when it makes another, then the response is 429 with a Retry-After header.
- Given a read-only OAuth token, when it calls the MCP server, then it is rejected and no tool runs.
- Given a rule previewed with 20 matches and then edited, when apply is called with the old token, then nothing is applied and a fresh preview is returned.
- Given an existing recurring series, when the API is asked to create an identical one, then the response is 409.

#### Maps to Can-I-Budget
- **Status:** Missing
- **Existing:** none
- **Gap:** There is no REST API, API keys, OAuth or user-facing MCP server, and no token-auth package is installed. `laravel/mcp` is already resolved, but only as a dev dependency pulled in by `laravel/boost` for its dev-only MCP server. Outside the Livewire pages, the HTTP endpoints are Fortify's auth routes, HealthCheckController's throttled health and readiness check (a database query and a Redis ping, failing with 500), the static home and setup-guide views, and Horizon's own JSON routes.
- **Build it like:**
  - Routing and auth: an API route group registered beside the web routes, with a token guard (a new dependency) and a new ApiKey model (C5).
  - Attribution: AttributeRequestToUser must be added to that group, because today it is appended only to the web group. Adding it is not enough: it reads the actor before the request runs and is prioritised ahead of authentication, so on a token group the actor is null and nothing is audited. For that group it must read the actor after the token guard runs, either from the request's user after the response or through a priority entry after authentication. A test asserts that an API write records an `http.<route>` AuditEvent against the token owner.
  - Inventory: every write route must be named and listed in UserActionInventoryTest (C4).
  - Scoping and money: every resource is re-scoped to the token user's `user_id` (C2), and money goes out as integer cents (C1).
  - MCP and tests: promote `laravel/mcp` to a production dependency rather than hand-writing a JSON-RPC endpoint. MCP tools wrap the same services the Livewire components use. Everything sits behind a `budget.*` flag (C6), with Pest feature tests (C7).

### F43. Family, users, roles, sharing and tenancy

#### What the user can do
- Belong to exactly one family. Roles are guest, member, admin and super admin.
- Admins invite people by email as member, admin or guest. An invitation is valid for 3 days, and admins can revoke pending ones.
- Admins remove members (but not themselves). A user can deactivate their own account.
- Account owners share each account with individual members at one of three levels: full control, read-write or read-only. A family default makes new accounts shared or private.
- Someone receiving a shared account chooses whether it counts in their own finances.
- A member can give another member view or edit access to their personal budget.
- Security settings:
  - password
  - email change with confirmation
  - authenticator-app two-factor with 8 backup codes
  - passkeys
  - OIDC single sign-on identities

#### Data relationships
- A family has many users, accounts and invitations, and destroying the family destroys all of them.
- Each account has one owner and many per-member shares. Deleting a user destroys their shares, chats, API keys and the invitations they sent.
- Removing a member also deletes their matching invitation.
- When a user is purged, their accounts pass to an admin, or to the oldest member if there is no admin. Purging the last member destroys the family.
- The first user on an instance becomes super admin. Anyone who later creates a new family becomes that family's admin.

#### Edge cases
- Editing an account's data needs owner or full control. Read-write members can still annotate: category, tags, notes and merchant.
- Automatic sharing gives guests read-only and everyone else read-write.
- Only one invitation per email can be pending in a family. An invitation pending in another family blocks a new one, and re-inviting after expiry replaces the expired invitation.
- Inviting an existing user adds them at once and updates their role. If they own accounts in another family, the invite is refused ("has family data").
- An admin cannot deactivate themselves while other members remain, and a member who owns accounts elsewhere cannot be removed.
- The last active super admin cannot be demoted, deactivated or destroyed.
- Self-hosted installs send no invitation email. The invitee uses the link or signs in with the invited address.
- Guests are kept on an introductory layout with the sidebar off.

#### Acceptance criteria
- Given an invitation pending for an email in another family, when an admin invites that email, then the invitation fails.
- Given the only super admin, when anyone tries to demote them, then it fails.
- Given an account shared read-only with a member, when that member changes a transaction's category, then the change is refused.
- Given an account shared with a member, when the share is revoked, then the account drops out of that member's balance sheet.
- Given a family of 2 in which one member owns 3 accounts, when that member is purged, then the 3 accounts pass to the admin.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - User authenticates through Fortify: registration, password reset, and TwoFactorAuthenticatable with confirmed TOTP and recovery codes. Fortify's email-verification routes exist, but User does not implement MustVerifyEmail, so nothing is sent at signup and the `verified` middleware blocks no one.
  - Settings pages cover password and two-factor; the two-factor page asks for the password first.
  - In production, passwords need at least 12 characters, mixed case, numbers and symbols, and must not appear in known breaches.
  - AuditAuthentication records login, logout, registration, password reset and verification. Failed logins and failed two-factor challenges are recorded as Failure.
  - Every user-owned model carries `user_id`, so each user is a separate tenant.
- **Decision:** Data stays per user (ADR `docs/decisions/0001-tenancy-stays-per-user.md`, #642). Households, roles, invitations, account sharing and ownership transfer on purge are not built. Passkeys are raised as a separate issue. OIDC single sign-on is not built.
- **Gap:** There is no family or household, roles, invitations, account sharing, permission levels, passkeys or SSO. Each login sees only its own data. That stays the target behaviour until a reopen condition in the ADR fires.
- **Build it like:**
  - Do not add Household, HouseholdMembership or AccountShare. If a reopen condition fires, the ADR sets the order of work: actor column on `audit_events`, a personal household per user, an accessible-accounts scope, then AccountShare, invitations and roles.
  - Spend, income and transfer classification stays on the account-wide `is_tracked` flag through Transaction::countable(), PlannedTransaction::countedDirection() and CategoryAttribution, whoever is looking.
  - Scoping is explicit `user_id` filters (C2). Category stays global.
  - Instance admin is the admin flag on User from F48, which is the one admin definition for the instance. There are no household roles.
  - Base currency, timezone, month-start day and assistant mode live on User through F44. Default sharing is not stored.
  - Migrations must run on both MariaDB and SQLite (C8).

### F44. Settings and preferences

#### What the user can do
- Profile: name, photo (JPEG or PNG up to 10 MB) and email.
- Display: theme (system, light or dark), language, default period, default account order, AI sidebar, and the default account for new transactions.
- Appearance: grouping of split transactions, a two-column dashboard, modals that stay open on an outside click, and account groups that start expanded.
- Toggle "suggest a rule after changing a category", and dismiss the "What's new" release highlights.
- Admins edit the family settings:
  - name, country, base currency and enabled currencies
  - date format (11 choices), timezone and locale
  - month-start day (1–28)
  - whether the group is called "Family" or "Group"
  - default account sharing, and personal and household budgets
  - assistant type, categorisation provider and AI prompt overrides
- Reset the family's data, optionally loading sample data. Request a family export archive. Delete their own account behind a warning that it is irreversible.

#### Data relationships
- Display and appearance preferences belong to one user. Family settings belong to the family and apply to every member.
- Changing the base currency changes only the reporting currency. Stored amounts keep their own currency.

#### Edge cases
- The language is chosen in this order: the URL, the user's locale, the browser's language (saved to the profile the first time), the family locale, then the app default.
- An unknown family timezone falls back to the app default and is logged at most once a day.
- With no custom month start, month-to-date uses calendar months. With start day N, "this month" and "last month" both begin on day N.

#### Acceptance criteria
- Given a month-start day of 15, when today is 20 March, then "this month" runs from 15 March to today.
- Given a user with no saved language and a German browser, when they load a page, then it renders in German and German is saved to their profile.
- Given a family reporting in AUD, when an admin switches the base currency to USD, then reports show USD and stored transaction amounts are unchanged.
- Given a 12 MB photo, when the user uploads it, then it is rejected.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Decision (ADR `docs/decisions/0001-tenancy-stays-per-user.md`, #642):** There is no family, so the "family settings" above become per-user settings. Each user edits their own base currency, timezone, locale, date format, month-start day and assistant mode. The family name, the "Family" or "Group" label, default account sharing and household budgets are not built. "Reset the family's data" and the family export mean the user's own data.
- **Existing:**
  - Profile settings page: name and email. Changing the email clears `email_verified_at`. The resend prompt never renders, because User does not implement MustVerifyEmail.
  - Password and two-factor pages.
  - Pay-cycle page: pay amount, PayFrequency and a next pay date of today or later, applied through PayCycleConfigurator.
  - Providers page: the Redbark or SimpleFIN feed, GmailConnection and AnalysisSuggestions.
  - Account deletion behind a password-confirmed modal.
  - User stores `primary_account_id`.
  - TransactionPeriod::PayCycle, resolved through User::currentPayCycleBounds, is the nearest existing equivalent of a month-start day.
- **Gap:** There is no photo, theme (the head partial forces the light theme), language, currency, date format, per-user timezone (only the single `app.timezone`), month-start day or display preferences, and no data reset.
- **Build it like:**
  - One preference store, owned by this feature. Settings that queries depend on (base currency, timezone, month-start day) are columns on User. UI preferences (dashboard layout, Sankey grouping, report section order, rule-prompt opt-out, preview features) live in one `preferences` JSON column on User, cast to a final DTO that sits with the existing DTO classes. Option enums back both (C5). Every other feature that needs a per-user preference stores it in this preference store.
  - Month-start day extends TransactionPeriod with a new case rather than adding a second period enum.
  - New settings pages beside the existing ones, with audited actions (C3).
  - A per-user timezone must apply to every date-boundary calculation, not only the explicit `app.timezone` reads in the BNPL scan, SimpleFinService and the email schedule strategies. Period boundaries, the pay cycle, the dashboard windows and the reports all take their "today" from `app.timezone` now, and each must resolve in the user's timezone.

### F45. Notifications

#### What the user can do
- Flash notices and alerts appear as dismissible toasts. One call-to-action slot carries the "create a category rule" prompt.
- Super admins see a banner when the background worker is down, and chat warns when the worker is unhealthy.
- On hosted installs the mobile app registers devices for push, and super admins can send a test push. High-priority insights also reach opted-in members as push messages (F23).
- Emails go out for invitations, email confirmation, password reset, PDF-import results, a digest of newly matching transactions to the family admin (F25) and a demo-refresh notice to the super admin. Bills offer a calendar (ICS) feed behind Sure's preview-features flag.
- There are no per-user notification preferences.

#### Data relationships
- Push subscriptions belong to one user and are destroyed with them.

#### Edge cases
- On self-hosted installs, push registration is refused as a disabled feature.

#### Acceptance criteria
- Given a self-hosted install, when a client registers a push subscription, then the response is 403.
- Given a down worker, when a super admin opens the app, then the warning banner shows. When a member opens it, there is no banner.
- Given a saved change that sets a flash notice, when the page renders, then a dismissible toast appears.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Flux toasts across components such as AnalysisSuggestions, TransferReview, UserRuleManager and PayeeReview.
  - BnplPendingBadge shows a count in the sidebar.
  - The Dashboard shows a "Connect your bank" card and statements that are due.
  - Fortify sends password-reset mail through the Notifiable User. A verification mail goes out only if the user opens the verify page and asks for a resend, because User does not implement MustVerifyEmail.
- **Gap:** There is no push, no worker-health banner (Horizon status is visible only on its own dashboard), no calendar feed, and no other email.
- **Build it like:** Laravel notifications on the Notifiable User, sent from queued jobs carrying AttributeJobToUser, each added to the UserActionInventoryTest job list with its audited flag (C4), behind a `budget.*` flag (C6). Where a feature needs dedupe, such as the F25 rule email, it keeps a delivery record but still sends through a notification.

### F46. Onboarding

#### What the user can do
- Go through a wizard: profile (name, Family or Group label, country), then preferences (theme, language, currency, date format), then goals (multi-select), then the trial.
- A user who has not finished onboarding is sent back to it from every normal page. Settings, subscription, onboarding, user and API pages are exempt.
- Registration can be closed, open or invite-only. Invite-only requires a one-time invite code and can attach new users to a default family as members.
- Someone joining by invitation skips family creation and sees the accepted invitation in the wizard.
- On managed installs there is a 45-day trial with reminders. After that, a family without a subscription is sent to the trial and upgrade pages. Self-hosted installs skip subscriptions.

#### Data relationships
- Each user records when they finished onboarding. Each invite code can be claimed once.

#### Edge cases
- A signup that fails never uses up the invite code, because the code is claimed in the same atomic step as the signup.

#### Acceptance criteria
- Given a user who has not finished onboarding, when they open the dashboard, then they are redirected to onboarding.
- Given invite-only registration, when someone signs up without a valid code, then registration is refused.
- Given an invite code whose signup fails validation, when the user retries with the same code, then the code still works.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Fortify registration, toggled by `FORTIFY_REGISTRATION_ENABLED`. RegisterResponse sends new users to ConnectBank.
  - ConnectBank steps, every one skippable:
    1. connect a Redbark key or a SimpleFIN setup token
    2. RedbarkAccountSetup
    3. ConfirmPayCycleStep
    4. ConfirmBudgetTags
    5. PayeeReview
    6. optional GmailConnection
  - The wizard resumes from the feed's state, and the Dashboard keeps its "Connect your bank" card while no RedbarkFeed exists.
- **Gap:** Onboarding is never enforced. Nothing records that it finished, no middleware sends unfinished users back, and every step offers "Skip for now". ConnectBank::mount derives progress from the feed and redirects to the dashboard once the feed has synced and the pay cycle is set, so new preference or goal steps would never reach users who finished bank setup. There are no preference or goal steps, no invite-only registration or invite codes, and no trial.
- **Build it like:**
  - Enforcement: an `onboarded_at` timestamp on User, set in ConnectBank::finish (a skip-all also finishes), and a middleware in the auth+verified group that sends users with it unset to ConnectBank. Settings, `connect-bank` and `redbark.setup` are exempt. ConnectBank::mount redirects to the dashboard only when `onboarded_at` is set, so the preference steps are reached after the pay-cycle step even once the feed is complete. The migration backfills `onboarded_at` for existing users who have finished bank setup.
  - Steps: add preference steps to ConnectBank that write to the F44 preference store.
  - Invite codes: a new InviteCode model (C5). Wrap CreateNewUser::create (the user, the month-end rule provisioning and the invite-code claim) in one `DB::transaction` so a failed signup never uses up a code (C8). Actions are audited (C3), and any new job or write route goes into UserActionInventoryTest (C4).

### F47. Preview-feature flags

#### What the user can do
- Each user switches preview features on or off in their preferences. Only an explicit "on" counts.
- The flag gates:
  - the insights feed and page
  - the cash-flow endpoint and Sankey preferences
  - goals and pledges
  - the plan hub (otherwise the plain budgets page)
  - the bills features
  - statement-vault and valuation tools
  - the bill and insight assistant tools
- Opening a gated page without the flag redirects to the dashboard with a "feature is in preview" notice.

#### Data relationships
- The flag is stored per user. A family counts as opted in when any member is, and that family-level check is used only by background jobs.

#### Edge cases
- Pages always check the viewer's own flag, never the family-level one.

#### Acceptance criteria
- Given a user with preview off, when they open the insights page, then they land on the dashboard with the preview notice.
- Given a family where one of 2 members opted in, when nightly insight regeneration runs, then that family is included.
- Given a user with preview off, when they open the bills page, then they are redirected.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - Deployment-wide flags `budget.recurring_detection`, `budget.jev_categorisation` and `budget.bnpl_email_import`, plus `services.context_dev.enrichment_enabled`.
  - BnplOrderReview renders nothing when its flag is off, and its actions return 404.
- **Gap:** The flags are environment settings for the whole install, not something each user opts into. A gated feature is hidden or its actions return 404; it does not redirect with a notice.
- **Build it like:** a preview-features boolean stored in the F44 preference store, checked together with the `budget.*` flag (C5, C6) and switched from a settings page with an audited action (C3).

### F48. Admin and self-hosting

#### What the user can do
- Admins edit self-hosting settings. Managed installs do not offer this page. The settings cover:
  - registration mode and the default family for invite-only signups (super admins only)
  - whether email confirmation is required
  - provider API keys, stored encrypted
  - LLM provider, model, endpoint and token budgets
  - the external assistant
  - securities providers on or off
  - auto-sync on or off and its daily time
  - whether pending transactions are included
  - the client id for logo lookup
- Super admins use an admin console:
  - list users grouped by family, filtered by role or trial status
  - change a user's role or reset their password
  - move a user to another family or a new one
  - permanently remove a user
- Super admins also delete empty families and invitations, manage SSO providers and identity blocks, and impersonate a user once that user approves.
- Observability: system health (jobs, AI probes, usage, test push), a debug event log, LLM usage and the job-queue UI.
- On self-hosted installs super admins generate and revoke invite codes and can refresh the demo family.

#### Data relationships
- Encrypted provider keys and instance settings belong to the instance, not to any family.

#### Edge cases
- A password reset works only for users who log in locally, and cannot be done in the same action as a family change.
- Permanently removing a user requires typing their email, blocks them from signing back in through SSO, and cannot target yourself.
- An invalid auto-sync time is rejected with "invalid sync time" and nothing is saved.
- A self-hosted install whose Redis is unreachable shows an error page.

#### Acceptance criteria
- Given a managed install, when any user, an admin included, opens the self-hosting settings, then the response is 403.
- Given a self-hosted install, when a non-admin saves the self-hosting settings, then the save is refused as not authorised.
- Given an auto-sync time of 25:00, when an admin saves, then the "invalid sync time" alert shows and nothing changes.
- Given a super admin, when they try to remove their own user, then it is refused.

#### Maps to Can-I-Budget
- **Status:** Partial
- **Existing:**
  - The Horizon dashboard, gated by `viewHorizon`: open to anyone in the local environment, otherwise only to emails listed in `horizon.authorized_emails`.
  - Health and liveness: HealthCheckController, `horizon:liveness` and `scheduler:heartbeat`.
  - The AuditEvent trail, pruned after `audit.retention_days`.
  - Sentry error reporting.
  - Instance settings come only from environment config, for example `FORTIFY_REGISTRATION_ENABLED` and provider keys under `services.*`.
  - `services.redbark.include_pending` is the switch for including pending transactions, and `app:sync-redbark-feeds` runs every six hours in place of an admin-set auto-sync.
- **Gap:** There is no admin role, user management, impersonation, invite codes or settings screen. The audit trail has no viewer.
- **Build it like:**
  - An admin flag on User is the single instance-admin definition. It replaces the `horizon.authorized_emails` allowlist, and `viewHorizon` checks it. Household roles from F43 stay separate and gate household actions only.
  - The admin area is made of audited Livewire components (C3, C5). Livewire actions need no test change; any new write route or job goes into UserActionInventoryTest (C4).
