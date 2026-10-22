# 0001. Data stays per user; no household

- **Status:** Accepted
- **Date:** 2026-10-22
- **Issue:** #642 (epic #641, plan feature F43, slice `F43-spike`)
- **Plan:** `docs/plans/2026-10-21-sure-feature-inventory.md`, section F43

## Context

Sure, the app the epic #641 inventory is based on, groups users into a family. A family has roles (guest, member, admin, super admin), email invitations, and per-account sharing at three levels (full control, read-write, read-only). Each share carries a viewer flag that says whether the shared account counts in the viewer's own finances. When a member is purged, their accounts pass to an admin.

Can-I-Budget has none of this. Each user is a separate tenant:

- Convention C2: every user-owned table has `user_id`, and every query filters on it explicitly. There are no global scopes. Child tables are scoped through their parent. `Category` and `CategoryGroup` are global.
- 23 migrations add a `user_id` column. `app/` holds 216 explicit `where('user_id', …)` filters, and 104 files touch user scoping (see the inventory below).
- `CategoryAttribution::atoms()` and `CategoryAttribution::query()` take a user id, not a set of accounts.
- `audit_events` has a single `user_id` column (nullable, `nullOnDelete`). It records who acted, and nothing separate for whose data was touched.
- No per-user timezone, month-start day or base currency exists. Dates resolve through `config('app.timezone')`. The settings on User today are `pay_amount`, `pay_frequency`, `next_pay_date` and `primary_account_id`.
- `viewHorizon` is gated on the `HORIZON_AUTHORIZED_EMAILS` allowlist, and F48 plans to replace it with an `is_admin` flag on User.
- Registration is gated by `FORTIFY_REGISTRATION_ENABLED`. The app is self-run.

About 100 sub-issues in #641 add new user-owned models. Each one needs a single scoping rule to build on.

## Decision

Data stays per user. Can-I-Budget does not add Household, HouseholdMembership or AccountShare. No login can see or change another login's data.

Reasons:

1. No sharing requirement has been raised, so the default is no sharing. This is a default made in the absence of that evidence, not a finding that nobody will want it. Reopen condition 1 covers the case where that changes.
2. Adopting households means rewriting the 216 explicit filters across 104 files, changing `CategoryAttribution` and its callers, and adding a separate actor column to `audit_events`. Doing that first would block the whole epic behind its largest piece of work.
3. Per-user tenancy already isolates unrelated households from each other, so a second family on the instance does not need this model.
4. The decision can be reopened. The conditions are listed under **Reopen conditions**, along with the order the work would follow.

## Scoping rule for every new user-owned model

From now on, every new model follows C2 as it is written today:

- A table that a user owns directly has a `user_id` column declared as `$table->foreignId('user_id')->constrained()->cascadeOnDelete()`. If the generated foreign-key name would exceed 64 characters, pass an explicit short name, as `statement_reconciliations` does with `constrained(indexName: 'sr_user_fk')`.
- Any index used for the model's per-user lookups starts with `user_id`. Index names stay at or under 64 characters, and migrations run on MariaDB and SQLite (C8).
- A child table, whose rows only exist under one parent row, does not get its own `user_id`. It is scoped through its parent's foreign key, and every query reaches it through a parent that was itself filtered by `user_id`.
- Every query filters on the owning user explicitly, through `where('user_id', $user->id)` or through a relation on the user such as `$user->accounts()`. There are no global scopes, and there is no shared "accessible accounts" scope.
- Reference data shared by all users (`Category`, `CategoryGroup`, and later the F40 `Security`) has no `user_id`. A user-specific change to shared reference data needs its own user-owned table. It never writes to the global row.
- `audit_events` keeps its existing nullable `user_id` with `nullOnDelete`, so the audit trail outlives a deleted user. New tables do not copy that pattern unless they also need to outlive their user.

## Spend, income and transfer classification

Whether a row counts as spend, income or a transfer depends only on the account-wide `is_tracked` flag, through:

- `Transaction::countable()` (`scopeCountable`, `app/Models/Transaction.php`)
- `PlannedTransaction::countedDirection()` (`app/Models/PlannedTransaction.php`)
- `CategoryAttribution` (`app/Support/Transactions/CategoryAttribution.php`)

Classification never depends on who is viewing. This holds now, and it holds if households are adopted later. A future viewer-side "include in my finances" flag would change only which accounts appear in that viewer's balance sheet and lists, never how a transaction is classified.

## Where per-user settings live

All of these live on User. The F44 preference store puts them there.

| Setting | Lives on | Notes |
|---|---|---|
| Base currency | User | Reporting currency only. Stored amounts keep their own currency (F3). |
| Timezone | User | Replaces `config('app.timezone')` for period boundaries once F44 lands. |
| Month-start day | User | 1 to 28, as in F44. |
| Assistant mode | User | |
| Default account sharing | Not stored | Dropped, because there is no sharing. |

Timezone, month-start day and base currency change how totals are calculated. Any feature that stores period-based results (F12 stored occurrences, F21 budget rollover, F22 goal history) must state what happens to stored periods when one of these settings changes.

## Instance admin

The `is_admin` flag on User (F48) is the only definition of instance admin. It gates instance operations: Horizon and the F48 admin console. It does not widen any per-user query over ledger data. There are no household roles. If households are adopted later, household roles gate household actions only and never grant instance admin.

## Reopen conditions

Reopen this decision when any of these becomes true:

1. A login needs to see or change accounts owned by another login, for example a partner.
2. A planned feature cannot be specified per user, for example a joint budget or a shared goal.
3. OIDC single sign-on is adopted, and identity-provider groups would need to map onto shared data.

Another unrelated family joining the instance is not a reopen condition.

If a condition fires, do the work in this order, so that each step changes nothing until the next one lands:

1. Add an actor column to `audit_events` beside `user_id`, so that "who acted" and "whose data" are recorded separately.
2. Create one personal household per user, with that user as its only admin.
3. Add an "accessible accounts" scope that at first returns exactly the user's own accounts, and move the files in the inventory onto it one by one. `CategoryAttribution` takes a set of accounts instead of a user id.
4. Only then add AccountShare, invitations and roles.

## Alternatives rejected

- **Households now (the F43 plan as written).** Gives full parity with Sure. Rejected because it rewrites every user-scoped query and blocks the epic for a capability no current user needs.
- **A nullable `household_id` on every new model from day one, with no sharing.** Rejected because every model would carry two owner keys with no rule for which one to filter on, and the column stays dead until households exist.
- **A partner signing in to act as the owner.** Rejected because `audit_events` has only `user_id`, so the partner's edits would be recorded as the owner's, and access would be all or nothing.
- **Each person connecting the same bank feed.** Rejected because the copies drift apart in categories and `is_tracked`, and any combined view counts them twice.
- **One database schema, or one app instance, per family.** Rejected because it multiplies migrations and job connection handling, and makes later sharing impossible.

## Follow-up issues

| Item | Decision |
|---|---|
| Invitations | Dropped. Reopened by condition 1. |
| Account sharing levels | Dropped. Reopened by condition 1. |
| Member removal and ownership transfer on purge | Dropped. Sure passes a purged member's accounts to an admin; Can-I-Budget does not. Deleting a user cascades through `cascadeOnDelete` and permanently deletes that user's accounts, transactions and everything else they own. This loss is accepted because there is no second member to receive them. Reopen condition 1 would bring transfer back. |
| Passkeys | Raise as a separate issue. Passkeys attach to User and do not depend on tenancy. |
| OIDC single sign-on | Dropped. Adopting it is reopen condition 3. |

## Inventory: what moves if households are adopted

These are the files in `app/` that reference user scoping today (104). They are not all the same kind of data. If households are adopted, only the account-backed files would move to the accessible-accounts scope: the account, transaction, planned-transaction, budget, import, reconciliation, transfer and report code, and the calendar and dashboard loaders over them. Personal resources would stay scoped to one user: rules and rule groups, payees, merchant brands, credentials and feeds (Gmail, Redbark), pipeline runs, and `AuditEvent`, which records who acted. The split per file is made when the work is raised, not in this ADR. Counts are per area.

| Area | Files |
|---|---|
| Livewire (24) | AccountManager, AnalysisSuggestions, BnplOrderReview, BnplPendingBadge, CalendarView, CategoryEditor, CategoryReport, ConfirmBudgetTags, ConfirmPayCycleStep, ConnectBank, Dashboard, FeedbackWidget, GmailConnection, ImportBank, PayeeReview, PlannedTransactionManager, ReconcileStatement, RecurringTransactionReview, RedbarkAccountSetup, TransactionList, TransactionModal, TransferReview, TransferRuleList, UserRuleManager |
| Livewire/Dashboard (2) | MonthlyProjection, PayCycleCalendar |
| Models (20) | Account, AnalysisSuggestion, AuditEvent, BankImport, BnplOrder, Budget, GmailCredential, MerchantBrand, Payee, PipelineRun, PlannedTransaction, RedbarkFeed, RedbarkSyncLog, StatementReconciliation, Transaction, TransactionEmail, TransferRule, UserCategoryBudgetTag, UserRule, UserRuleGroup |
| Services (18) | CategoryRuleGenerator, CategoryRuleMiner, FirstImportSummary, IncomePatternDetector, ManualContradictionChecker, MonthEndBalanceRuleProvisioner, PayCycleConfigurator, PlannedTransactionMatcher, ReconciliationMatcher, RuleActionExecutor, RuleActionLookups, RuleBacklogApplier, RuleTester, SuggestionApplier, TransactionAnalysisPipeline, TransactionEmailLinker, TransactionFeeFolder, UserRuleApplier |
| Services/PipelineStages (5) | IdentifyPrimaryAccountStage, PayeeSuggestionStage, RuleMiningStage, SetPayCycleStage, UserRulesStage |
| Services/Transfers (4) | TransferDetector, TransferLinker, TransferReviewQueue, UntrackedAccountCreator |
| Services/Bnpl (3) | BnplAccountResolver, BnplOrderImporter, BnplReceiptLinker |
| Services/Payees (3) | PayeeConfirmer, PayeeReviewQueue, PayeeSuggester |
| Services/MerchantBrands (2) | BrandNameWriter, RepresentativeTransaction |
| Services/Recurring (2) | RecurringSuggestionWriter, RecurringTransactionDetector |
| Services, other (3) | Projection/MonthlyProjectionService, Reports/ReportAggregator, Statement/StatementReconciler |
| Jobs (4) | EnrichMerchantBrandsJob, ImportCsvTransactionsJob, ResolveMerchantBrandJob, SyncRedbarkFeedJob |
| Console/Commands (3) | BackfillMerchantKeysCommand, JevEvalCommand, ScanBnplEmailsCommand |
| Actions (3) | Onboarding/ConfirmPayCycle, Redbark/SaveRedbarkApiKey, Redbark/SaveSimpleFinAccessUrl |
| Support (4) | Audit/AuditRecorder, Calendar/DayActivityLoader, Calendar/MonthFlowLoader, Transactions/CategoryAttribution |
| Listeners (2) | BnplPaymentFanout, RememberBnplCategory |
| Http/Middleware (1) | AttributeRequestToUser |
| View/Composers (1) | LayoutShellComposer |

`CategoryAttribution::query()` has five callers that pass a user id: `CategoryEditor` (twice), `Dashboard`, `Budget` and `ReportAggregator`.

## Consequences

- Every new model in #641 follows the scoping rule above. No sub-issue introduces household or sharing columns.
- F43's plan-doc text for Household, HouseholdMembership and AccountShare is kept only as the target design if a reopen condition fires.
- F44 puts base currency, timezone, month-start day and assistant mode on User.
- F48's `is_admin` stays the single instance-admin definition.
- Per-user features that Sure frames as family-wide (the F36 export, F44 family settings, F21 household budgets) are built per user.
