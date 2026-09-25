<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Contracts\ContextDevServiceContract;
use App\Contracts\GmailServiceContract;
use App\DTOs\CategoryRulePreview;
use App\DTOs\EmailSearchResult;
use App\Enums\CategorySource;
use App\Enums\MerchantBrandStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Exceptions\GmailSearchException;
use App\Jobs\ResolveMerchantBrandJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\TransactionSplit;
use App\Services\CategoryRuleGenerator;
use App\Services\GmailService;
use App\Services\MerchantBrands\ContextDevCreditBalance;
use App\Services\MerchantBrands\ContextDevCreditBudget;
use App\Services\MerchantBrands\DescriptorGate;
use App\Support\AmountParser;
use ContextDev\Core\Exceptions\ContextDevException;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

final class TransactionList extends Component
{
    use WithPagination;

    private const array SORTABLE_COLUMNS = ['post_date', 'amount', 'description'];

    private const array VALID_DIRECTIONS = ['all', 'incoming', 'outgoing'];

    private const array VALID_PLANNED_FILTERS = ['all', 'planned', 'unplanned'];

    private const array VALID_CATEGORISED_FILTERS = ['all', 'categorised', 'uncategorised'];

    /**
     * Provenance filter. 'rule' surfaces everything automation decided, which
     * is what makes accepting automation reversible rather than a one-way door.
     */
    private const array VALID_SOURCE_FILTERS = ['all', 'manual', 'rule'];

    private const array VALID_GROUP_MODES = ['date', 'merchant'];

    /**
     * Clusters are a coarser unit than rows, and each expands in place, so a
     * page of 25 clusters is already a long scroll.
     */
    private const int CLUSTERS_PER_PAGE = 25;

    #[Url]
    public string $direction = 'all';

    #[Url]
    public string $planned = 'all';

    #[Url]
    public string $categorised = 'all';

    #[Url]
    public string $source = 'all';

    #[Url]
    public ?int $account = null;

    #[Url]
    public ?int $category = null;

    #[Url]
    public string $period = 'this-month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url(except: '')]
    public string $search = '';

    #[Url]
    public string $sortBy = 'post_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $groupMode = 'date';

    /**
     * The one expanded cluster, mirroring the exclusive-panel pattern the split
     * and email panels already use. Not #[Locked]: it is set from the client.
     * Safety comes from the user_id scope on every query, never from locking.
     */
    #[Url]
    public ?string $expandedKey = null;

    /**
     * Hand-picked row selection, keyed by transaction id.
     *
     * Deliberately NOT #[Locked]: a locked property throws
     * CannotUpdateLockedPropertyException on any client update, so it cannot
     * back a checkbox wire:model. Locking is not the security mechanism here —
     * every bulk write re-scopes to where('user_id', auth()->id()), so forged
     * ids resolve to nothing.
     *
     * @var array<int|string, bool>
     */
    public array $selected = [];

    /**
     * A frozen filter snapshot standing in for "everything matching this",
     * optionally narrowed to one merchant_key.
     *
     * Storing the snapshot rather than hundreds of ids keeps the component
     * payload small and means the set is re-resolved at commit time. It is a
     * snapshot, not live filters, so the number shown on the button is the
     * number that gets written even if the user changes a filter afterwards.
     *
     * #[Locked] unlike $selected: nothing binds this property, it is written
     * only by selectAllMatching(), selectCluster(), clearSelection(),
     * toggleVisible() and updatedSelected(). Locking it keeps an arbitrary
     * client-supplied array out of the query builder entirely.
     *
     * Typed loosely on purpose. The shape this class writes is
     * array{filters: array<string, mixed>, merchantKey: string|null}, but
     * declaring that would make the shape checks in selectionQuery() statically
     * dead — and those checks are what makes a malformed scope fail closed
     * rather than widen a bulk write to every matching row. The guards are the
     * guarantee; the lock is defence in depth in front of them.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $bulkScope = null;

    /**
     * Rows the user has taken back out of the held scope.
     *
     * A scope stands for "everything matching this", which is exactly why a
     * hand-pick must not drop it: unticking one row of a 700-row filter-wide
     * selection means "the other 699", and collapsing to whatever happens to
     * be on screen would silently discard every matching row on another page.
     * The scope keeps its reach and this list records the subtractions, so the
     * payload grows only by what the user actually deselects.
     *
     * #[Locked] for the same reason as $bulkScope: nothing binds it, it is
     * written only by the selection mutators, and selectionQuery() feeds it
     * straight into a whereNotIn.
     *
     * @var list<int>
     */
    #[Locked]
    public array $bulkExcluded = [];

    public string $bulkCategoryId = '';

    #[Locked]
    public ?string $bulkError = null;

    #[Locked]
    public ?string $bulkNotice = null;

    /**
     * The editable `description contains` value the generated rule will carry.
     * Defaulted from CategoryRuleGenerator::suggestMatchValue(), then the
     * user's to change before anything is created.
     */
    public string $ruleMatchValue = '';

    /**
     * Set while the rule preview panel is open. The rule is created only on a
     * second, explicit confirmation, because unlike "apply to these N" a rule
     * is auto-apply and sweeps the user's whole history.
     */
    #[Locked]
    public bool $rulePanelOpen = false;

    /**
     * The dry-run shown in the panel. Held rather than computed in render():
     * it walks the user's whole history, so it is recomputed only when one of
     * its inputs moves — the match value, the category, or the selection, and
     * the last of those closes the panel instead.
     */
    #[Locked]
    public ?CategoryRulePreview $rulePreview = null;

    #[Locked]
    public ?int $emailPanelTxnId = null;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $emailResults = [];

    #[Locked]
    public ?string $emailScanError = null;

    #[Locked]
    public ?int $splitPanelTxnId = null;

    /** @var list<array<string, mixed>> */
    public array $splitLines = [];

    #[Locked]
    public ?string $splitError = null;

    /**
     * Per-request memo for matchingEligibleCount(). Private, so Livewire never
     * serialises it and it cannot survive into the next request.
     */
    private ?int $matchingCountCache = null;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function mount(): void
    {
        $this->restoreRememberedPeriod();

        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }

        if (! in_array($this->planned, self::VALID_PLANNED_FILTERS, true)) {
            $this->planned = 'all';
        }

        if (! in_array($this->categorised, self::VALID_CATEGORISED_FILTERS, true)) {
            $this->categorised = 'all';
        }

        if (! in_array($this->source, self::VALID_SOURCE_FILTERS, true)) {
            $this->source = 'all';
        }

        if (! in_array($this->groupMode, self::VALID_GROUP_MODES, true)) {
            $this->groupMode = 'date';
        }

        // Clustering only exists while triaging uncategorised rows, and the
        // toggle that leaves it is rendered only there. Any other filter would
        // strand the user in merchant mode with no visible way back, including
        // on a direct ?groupMode=merchant&categorised=all load.
        if ($this->categorised !== 'uncategorised') {
            $this->groupMode = 'date';
        }

        // The provenance toggle is hidden while triaging uncategorised rows,
        // and an uncategorised row has no source (saving() nulls it), so any
        // value but 'all' would empty the list with no visible cause.
        if ($this->categorised === 'uncategorised') {
            $this->source = 'all';
        }

        // An expanded cluster only means anything in merchant mode; carrying a
        // stale key into date mode would leave a dead query-string parameter.
        if ($this->groupMode !== 'merchant') {
            $this->expandedKey = null;
        }

        $this->period = match ($this->period) {
            '30d' => 'this-month',
            '90d' => '3m',
            '12m' => '1y',
            default => $this->period,
        };

        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }
    }

    /**
     * Expand one cluster, collapsing whatever was open. Re-clicking the open
     * cluster closes it.
     */
    public function toggleCluster(string $merchantKey): void
    {
        $this->expandedKey = $this->expandedKey === $merchantKey ? null : $merchantKey;
    }

    public function updatedGroupMode(): void
    {
        if (! in_array($this->groupMode, self::VALID_GROUP_MODES, true)) {
            $this->groupMode = 'date';
        }

        // Same coherence rule as mount() and updatedCategorised(): the toggle
        // that leaves merchant mode is rendered only while triaging, so the
        // mode cannot be entered from anywhere else. A client update can set
        // this property directly, which is why the clamp lives on every path
        // rather than only where the UI can reach it.
        if ($this->categorised !== 'uncategorised') {
            $this->groupMode = 'date';
        }

        $this->expandedKey = null;
        $this->resetPage();
    }

    /**
     * A hand-pick while a scope is held narrows the scope, it does not destroy
     * it: the row is recorded as an exclusion and every other matching row,
     * on screen or not, stays selected. Only a tick on rows the scope does not
     * cover — a different cluster, or the page after a filter change — drops
     * it, because there the user is plainly working on something else.
     */
    public function updatedSelected(): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;
        $this->closeRulePanel();

        if ($this->bulkScope !== null && $this->scopeCoversVisibleRows()) {
            $this->applyVisibleTicksToScope();

            return;
        }

        $this->bulkScope = null;
        $this->bulkExcluded = [];
    }

    /**
     * Tick or untick every eligible row currently on screen: the page in date
     * mode, the expanded cluster in merchant mode.
     *
     * @param  list<int|string>  $ids  client-supplied through wire:click, so
     *                                 each is cast before it becomes a key
     */
    public function toggleVisible(array $ids, bool $select): void
    {
        // Same reset as updatedSelected(): the notice is a persistent inline
        // element, not a toast, so without this a fresh selection renders
        // directly beneath "Categorised 25 transactions." from the previous
        // apply and reads as though it has already been written.
        $this->bulkError = null;
        $this->bulkNotice = null;
        $this->closeRulePanel();

        // "Clear these 25" under a 700-row scope means the 25 on screen, not
        // all 700, so it subtracts from the scope rather than discarding it.
        if ($this->bulkScope !== null && $this->scopeCoversVisibleRows()) {
            $excluded = array_flip($this->bulkExcluded);

            foreach ($ids as $id) {
                if ($select) {
                    unset($excluded[(int) $id]);
                } else {
                    $excluded[(int) $id] = true;
                }
            }

            $this->bulkExcluded = array_map(intval(...), array_keys($excluded));

            if ($this->selectionCount() === 0) {
                $this->clearSelection();
            }

            return;
        }

        $this->bulkScope = null;
        $this->bulkExcluded = [];

        foreach ($ids as $id) {
            if ($select) {
                $this->selected[(int) $id] = true;
            } else {
                unset($this->selected[(int) $id]);
            }
        }
    }

    /**
     * Select everything matching the current filters — an explicitly separate
     * action from the on-screen checkbox, so "all" never silently means
     * something other than what the user clicked.
     */
    public function selectAllMatching(): void
    {
        $this->bulkScope = ['filters' => $this->currentFilters(), 'merchantKey' => null];
        $this->bulkExcluded = [];
        // render() mirrors the scope into the checkboxes on screen, so this
        // stays empty and cannot go stale when the page or filters move.
        $this->selected = [];
        $this->bulkError = null;
        $this->bulkNotice = null;
        $this->closeRulePanel();
    }

    /**
     * Select every eligible row in one cluster, however many pages it spans.
     * The write set is the scope, not an id list, so a cluster spanning more
     * than the screen costs one string rather than hundreds of ids.
     */
    public function selectCluster(string $merchantKey): void
    {
        $this->bulkScope = ['filters' => $this->currentFilters(), 'merchantKey' => $merchantKey];
        $this->bulkExcluded = [];
        $this->selected = [];
        $this->bulkError = null;
        $this->bulkNotice = null;
        $this->closeRulePanel();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->bulkScope = null;
        $this->bulkExcluded = [];
        $this->bulkCategoryId = '';
        $this->bulkError = null;
        $this->bulkNotice = null;
        $this->closeRulePanel();
    }

    /**
     * Apply one category to the current selection, creating no rule, so no
     * future import is affected.
     *
     * Bounded to exactly the rows the selection resolves to. Each row is saved
     * individually rather than through a mass UPDATE so a category change still
     * dispatches TransactionCategoryUpdated — Transaction::booted() gates that
     * event on wasChanged('category_id'), so a save that only restamps
     * provenance is written but fires nothing, which is correct.
     *
     * The models carry propagateCategoryChange = false, so
     * PropagateTransactionCategory declines to fan the change out across
     * planned_transaction_id. That fan-out is scoped by planned group, not by
     * what the user ticked, so without the opt-out a bulk apply would rewrite
     * any unselected sibling sharing the plan: ordinary rows the list offered
     * with an enabled checkbox and the user deliberately left alone, and the
     * transfers and splits eligibleForBulk() renders disabled with a stated
     * reason. Every other writer, including a single-row edit and
     * RuleActionExecutor, keeps the grouping behaviour untouched.
     *
     * @throws Throwable
     */
    public function applyCategoryToSelection(): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;

        $categoryId = $this->chosenBulkCategoryId();

        if ($categoryId === null) {
            return;
        }

        $query = $this->selectionQuery();

        if ($query === null) {
            $this->bulkError = 'Nothing is selected.';

            return;
        }

        // Counted from the database before the write rather than tallied in
        // the loop, so the number shown is the number of rows the write
        // changes.
        //
        // The predicate is the negation of the loop's isDirty() test, spelled
        // out rather than expressed as NOT (a AND b) because SQL's three-valued
        // logic would drop NULL rows out of a negated conjunction. Counting on
        // category_id alone would under-report: re-applying a category rows
        // already hold still restamps rule-assigned rows as Manual — the whole
        // "lock these in so rules stop overwriting them" workflow — and would
        // have reported "Categorised 0 transactions." while doing it.
        $updated = (clone $query)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('category_id')
                ->orWhere('category_id', '!=', $categoryId)
                ->orWhereNull('category_source')
                ->orWhere('category_source', '!=', CategorySource::Manual->value))
            ->count();

        // Chunked model saves, never a mass UPDATE: a mass update bypasses
        // Eloquent events, so TransactionCategoryUpdated would not fire at all.
        //
        // chunkById is a keyset cursor (id > lastId), not an offset, so rows
        // dropping out of the filter predicate as the loop writes them stay
        // behind the cursor and cannot be skipped.
        DB::transaction(static function () use ($query, $categoryId): void {
            $query->chunkById(200, function (EloquentCollection $chunk) use ($categoryId): void {
                foreach ($chunk as $transaction) {
                    $transaction->category_id = $categoryId;
                    // A direct human choice, so it is protected from later rule
                    // overwrites by the provenance guard.
                    $transaction->category_source = CategorySource::Manual;
                    // This row and no other. Also removes the O(N²) write a
                    // fan-out would cause for a selection spanning one planned
                    // group: N saves each mass-updating the same N siblings.
                    $transaction->propagateCategoryChange = false;

                    // Re-applying a category a row already carries is a no-op
                    // save; skip it rather than firing a pointless event.
                    if (! $transaction->isDirty()) {
                        continue;
                    }

                    $transaction->save();
                }
            });
        });

        $this->clearSelection();
        // The write can shrink the result set past the current page — applying
        // on the last page of an uncategorised filter removes that page
        // entirely — and an out-of-range page renders the empty state while
        // rows still match. Go back to page one so the remainder is visible.
        $this->resetPage();
        $this->bulkNotice = $updated === 1
            ? 'Categorised 1 transaction.'
            : sprintf('Categorised %d transactions.', $updated);

        $this->dispatch('transaction-saved');
    }

    /**
     * Undo machine-set categories across the selection.
     *
     * This is what stops automation being a one-way door: everything a rule
     * decided is stamped CategorySource::Rule, so it can be found and undone in
     * one action. Rows a human categorised are never touched, which is the
     * whole point of tracking provenance.
     */
    public function revertMachineCategories(): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;

        $query = $this->selectionQuery();

        if ($query === null) {
            $this->bulkError = 'Nothing is selected.';

            return;
        }

        $reverted = 0;

        DB::transaction(function () use ($query, &$reverted): void {
            $query->where('category_source', CategorySource::Rule->value)
                ->chunkById(200, function (EloquentCollection $chunk) use (&$reverted): void {
                    foreach ($chunk as $transaction) {
                        // Clearing category_id nulls category_source through the
                        // model's invariant hook, so provenance cannot be left
                        // claiming a source for an absent category.
                        $transaction->category_id = null;
                        // The planned-group fan-out would clear siblings the
                        // user did not select, including ones they categorised
                        // themselves, and the plan's own category.
                        $transaction->propagateCategoryChange = false;
                        $transaction->save();
                        $reverted++;
                    }
                });
        });

        $this->clearSelection();
        // Every reverted row leaves the 'rule' view it was reverted from, so a
        // later page may now be past the end. Start again from page one.
        $this->resetPage();
        $this->bulkNotice = $reverted === 1
            ? 'Reverted 1 rule-set category.'
            : sprintf('Reverted %d rule-set categories.', $reverted);

        $this->dispatch('transaction-saved');
    }

    /**
     * Open the rule preview. Nothing is created here — this exists so the user
     * sees how far the rule reaches *before* committing, since unlike "apply to
     * these N" a generated rule is auto-apply and sweeps all history.
     */
    public function openRulePanel(CategoryRuleGenerator $generator): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;

        $source = $this->ruleSourceTransaction();

        if ($source === null) {
            $this->bulkError = 'Nothing is selected.';

            return;
        }

        if ($this->ruleMatchValue === '') {
            // Not the cluster's merchant_key, even in cluster mode: the key is
            // a normalised signature ('PAYPAL STEAM' for 'PAYPAL *STEAM 4829')
            // and rarely a substring of the raw description the rule's
            // `description contains` trigger tests against.
            $this->ruleMatchValue = $generator->suggestMatchValue($source);
        }

        $this->rulePanelOpen = true;
        $this->refreshRulePreview();
    }

    public function updatedRuleMatchValue(): void
    {
        $this->refreshRulePreview();
    }

    public function updatedBulkCategoryId(): void
    {
        $this->refreshRulePreview();
    }

    /**
     * Also the reset every selection mutator runs: a panel left open across a
     * selection change would come back pre-filled with a trigger built from
     * rows that are no longer selected.
     */
    public function closeRulePanel(): void
    {
        $this->rulePanelOpen = false;
        $this->ruleMatchValue = '';
        $this->rulePreview = null;
    }

    /**
     * Create one rule from the selection and sweep past and future.
     *
     * One rule, not one per selected row: N rules would be near-duplicates and
     * would each trigger their own full-history sweep.
     */
    public function createRuleFromSelection(CategoryRuleGenerator $generator): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;

        $categoryId = $this->chosenBulkCategoryId();

        if ($categoryId === null) {
            return;
        }

        if (mb_trim($this->ruleMatchValue) === '') {
            $this->bulkError = 'Give the rule something to match on.';

            return;
        }

        $source = $this->ruleSourceTransaction();

        if ($source === null) {
            $this->bulkError = 'Nothing is selected.';

            return;
        }

        $preview = $generator->preview($source, $categoryId, $this->ruleMatchValue, $this->resolvedSelectionIds());

        $generator->generateAndApply($source, $categoryId, $this->ruleMatchValue);

        $this->clearSelection();
        // Same failure mode as applyCategoryToSelection(): the sweep can empty
        // the current page under the uncategorised filter.
        $this->resetPage();

        $this->bulkNotice = sprintf(
            'Rule created. %d transaction%s categorised%s.',
            $preview->wouldChange,
            $preview->wouldChange === 1 ? '' : 's',
            $preview->protectedByManual > 0
                ? sprintf(', %d left alone because you set them yourself', $preview->protectedByManual)
                : '',
        );

        $this->dispatch('transaction-saved');
    }

    /**
     * Whether a row may be bulk-categorised. Mirrors eligibleForBulk() for a
     * single already-loaded model so the view can disable the checkbox and say
     * why, instead of silently ignoring the tick.
     */
    public function isBulkExcluded(Transaction $transaction): bool
    {
        return $transaction->transfer_pair_id !== null || $transaction->isSplit();
    }

    public function bulkExclusionReason(Transaction $transaction): ?string
    {
        if ($transaction->transfer_pair_id !== null) {
            return 'Transfers are not spending, so they are not categorised here.';
        }

        if ($transaction->isSplit()) {
            return 'This transaction is split; its category comes from its split lines.';
        }

        return null;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    /**
     * The modal saved or deleted a transaction/plan; re-render so the fresh
     * render() query picks up the change. A split or hand-set category also
     * changes what a pending rule would do, so an open preview is recomputed.
     */
    #[On('transaction-saved')]
    public function refreshList(): void
    {
        $this->refreshRulePreview();
    }

    /**
     * Queue a paid Context.dev lookup for this row's merchant. A user request
     * skips the recurrence threshold but not the gate, veto, retry window, kill
     * switch or daily cap, which the job re-checks.
     */
    public function identifyMerchant(int $transactionId, DescriptorGate $gate, ContextDevCreditBudget $budget): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        if (! config('services.context_dev.enrichment_enabled') || $transaction->merchant_key === null || ! $gate->allows($transaction)) {
            Flux::toast(text: 'This transaction cannot be matched to a merchant.', variant: 'warning');

            return;
        }

        if ($budget->remaining() < ContextDevCreditBudget::BRAND_LOOKUP_CREDITS) {
            Flux::toast(text: "Today's merchant lookup allowance is used up. Try again tomorrow.", variant: 'warning');

            return;
        }

        ResolveMerchantBrandJob::dispatch(auth()->user(), $this->merchantKeyFor($transaction));

        Flux::toast(text: 'Looking up the merchant. Refresh in a moment to see it.', variant: 'success');
    }

    /**
     * "Wrong merchant": hide the brand for this merchant key and never look it up
     * again for this user. Transactions themselves are untouched.
     */
    public function vetoMerchantBrand(int $transactionId): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        MerchantBrand::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'merchant_key' => $this->merchantKeyFor($transaction)],
            ['status' => MerchantBrandStatus::Vetoed, 'retry_after' => null],
        );

        Flux::toast(text: 'Merchant hidden. It will not be suggested again.', variant: 'success');
    }

    public function scanEmail(int $transactionId, GmailServiceContract $gmail): void
    {
        if ($this->emailPanelTxnId === $transactionId) {
            $this->emailPanelTxnId = null;
            $this->emailResults = [];
            $this->emailScanError = null;

            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        $this->emailPanelTxnId = $transactionId;
        $this->emailResults = [];
        $this->emailScanError = null;

        try {
            $this->emailResults = $gmail->searchForTransaction($transaction)
                ->map(fn (EmailSearchResult $result): array => $result->toArray())
                ->all();
        } catch (GmailSearchException $e) {
            Log::warning('Gmail scan failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            $this->emailScanError = 'Could not search Gmail — check the GMAIL_* credentials and connection.';
        }
    }

    public function linkEmail(int $transactionId, int $index): void
    {
        if ($this->emailPanelTxnId !== $transactionId || ! isset($this->emailResults[$index])) {
            return;
        }

        $result = $this->emailResults[$index];

        $messageId = $result['messageId'] ?? null;

        if (! is_string($messageId) || mb_trim($messageId) === '') {
            return;
        }

        $messageId = mb_trim($messageId);

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        TransactionEmail::query()->firstOrCreate(
            [
                'transaction_id' => $transaction->id,
                'gmail_message_id' => $messageId,
            ],
            [
                'user_id' => auth()->id(),
                'subject' => is_string($result['subject'] ?? null) ? $result['subject'] : '',
                'from_name' => is_string($result['fromName'] ?? null) ? $result['fromName'] : null,
                'from_address' => is_string($result['fromAddress'] ?? null) ? $result['fromAddress'] : '',
                'email_date' => is_string($result['date'] ?? null) ? $result['date'] : null,
                'snippet' => is_string($result['snippet'] ?? null) ? $result['snippet'] : null,
                'gmail_url' => GmailService::deepLink($messageId),
                'details' => is_array($result['details'] ?? null) ? $result['details'] : null,
            ],
        );
    }

    public function unlinkEmail(int $emailId): void
    {
        TransactionEmail::query()
            ->where('user_id', auth()->id())
            ->whereKey($emailId)
            ->delete();
    }

    public function toggleSplit(int $transactionId): void
    {
        if ($this->splitPanelTxnId === $transactionId) {
            $this->closeSplitPanel();

            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->with('splits')
            ->findOrFail($transactionId);

        if ($transaction->transfer_pair_id !== null) {
            return;
        }

        $this->splitPanelTxnId = $transactionId;
        $this->splitError = null;

        if ($transaction->splits->isNotEmpty()) {
            $this->splitLines = $transaction->splits
                ->map(static fn (TransactionSplit $split): array => [
                    'category_id' => $split->category_id === null ? '' : (string) $split->category_id,
                    'amount' => number_format(abs((int) $split->amount) / 100, 2, '.', ''),
                    'notes' => $split->notes ?? '',
                ])
                ->all();

            return;
        }

        $this->splitLines = [
            ['category_id' => $transaction->category_id === null ? '' : (string) $transaction->category_id, 'amount' => '', 'notes' => ''],
            ['category_id' => '', 'amount' => '', 'notes' => ''],
        ];
    }

    public function addSplitLine(): void
    {
        if ($this->splitPanelTxnId === null) {
            return;
        }

        $this->splitLines[] = ['category_id' => '', 'amount' => '', 'notes' => ''];
    }

    public function removeSplitLine(int $index): void
    {
        if (! isset($this->splitLines[$index])) {
            return;
        }

        unset($this->splitLines[$index]);
        $this->splitLines = array_values($this->splitLines);
    }

    public function assignRemainderToLast(): void
    {
        if ($this->splitPanelTxnId === null || $this->splitLines === []) {
            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->splitPanelTxnId);

        if ($transaction === null) {
            return;
        }

        $lastIndex = array_key_last($this->splitLines);
        $others = 0;

        foreach ($this->splitLines as $index => $line) {
            if ($index === $lastIndex) {
                continue;
            }

            $others += AmountParser::parse((string) ($line['amount'] ?? ''))->amount;
        }

        $remainder = abs((int) $transaction->amount) - $others;
        $this->splitLines[$lastIndex]['amount'] = number_format(max(0, $remainder) / 100, 2, '.', '');
    }

    /**
     * @throws Throwable
     */
    public function saveSplit(): void
    {
        $this->splitError = null;

        if ($this->splitPanelTxnId === null) {
            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->splitPanelTxnId);

        if ($transaction->transfer_pair_id !== null) {
            $this->splitError = 'Transfers cannot be split.';

            return;
        }

        $sign = (int) $transaction->amount < 0 ? -1 : 1;

        $lines = [];

        foreach ($this->splitLines as $line) {
            $categoryId = $line['category_id'] ?? null;
            $cents = AmountParser::parse((string) ($line['amount'] ?? ''))->amount;

            if ($categoryId === '' || ! is_numeric($categoryId)) {
                $this->splitError = 'Every split line needs a category.';

                return;
            }

            if ($cents <= 0) {
                $this->splitError = 'Every split line needs an amount greater than zero.';

                return;
            }

            $notes = is_string($line['notes'] ?? null) ? mb_trim($line['notes']) : '';

            if (mb_strlen($notes) > 255) {
                $this->splitError = 'Split notes must be 255 characters or fewer.';

                return;
            }

            $lines[] = [
                'category_id' => (int) $categoryId,
                'amount' => $sign * $cents,
                'notes' => $notes === '' ? null : $notes,
            ];
        }

        if (count($lines) < 2) {
            $this->splitError = 'A split needs at least two lines.';

            return;
        }

        if (array_sum(array_column($lines, 'amount')) !== (int) $transaction->amount) {
            $this->splitError = 'Split lines must add up to the transaction total.';

            return;
        }

        $categoryIds = array_column($lines, 'category_id');

        if (Category::query()->whereIn('id', $categoryIds)->count() !== count(array_unique($categoryIds))) {
            $this->splitError = 'One or more categories are invalid.';

            return;
        }

        DB::transaction(static function () use ($transaction, $lines): void {
            $transaction->splits()->delete();

            foreach ($lines as $position => $line) {
                $transaction->splits()->create([
                    'category_id' => $line['category_id'],
                    'amount' => $line['amount'],
                    'notes' => $line['notes'],
                    'position' => $position,
                ]);
            }
        });

        $this->closeSplitPanel();
        $this->dispatch('transaction-saved');
    }

    public function unsplit(int $transactionId): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        $transaction->splits()->delete();

        if ($this->splitPanelTxnId === $transactionId) {
            $this->closeSplitPanel();
        }

        $this->dispatch('transaction-saved');
    }

    public function updatedDirection(): void
    {
        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }

        $this->resetPage();
    }

    public function updatedPlanned(): void
    {
        if (! in_array($this->planned, self::VALID_PLANNED_FILTERS, true)) {
            $this->planned = 'all';
        }

        $this->resetPage();
    }

    public function updatedCategorised(): void
    {
        if (! in_array($this->categorised, self::VALID_CATEGORISED_FILTERS, true)) {
            $this->categorised = 'all';
        }

        // Same coherence rule as mount(): leaving uncategorised hides the mode
        // toggle, so the mode itself has to come back to date.
        if ($this->categorised !== 'uncategorised') {
            $this->groupMode = 'date';
            $this->expandedKey = null;
        }

        // Mirror of mount(): the provenance toggle is hidden here.
        if ($this->categorised === 'uncategorised') {
            $this->source = 'all';
        }

        $this->resetPage();
    }

    public function updatedSource(): void
    {
        if (! in_array($this->source, self::VALID_SOURCE_FILTERS, true)
            || $this->categorised === 'uncategorised') {
            $this->source = 'all';
        }

        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
        session()->put('transactions.period', $this->period);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
        session()->put('transactions.from', $this->from);
    }

    public function updatedTo(): void
    {
        $this->resetPage();
        session()->put('transactions.to', $this->to);
    }

    public function render(): View
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;
        $filters = $this->currentFilters();

        $inMerchantMode = $this->inMerchantMode();

        // Merchant mode paginates clusters, date mode paginates rows. Keeping
        // them in separate variables means the view never has to guess whether
        // it holds a paginator or a plain collection.
        $transactions = $inMerchantMode ? null : $this->paginatedRows($filters);

        $accounts = Account::query()
            ->where('user_id', auth()->id())
            ->active()
            ->get(['id', 'name']);

        $grouped = $transactions === null
            ? collect()
            : $transactions->getCollection()
                ->groupBy(static fn (Transaction $t): string => $t->post_date->format('Y-m-d'));

        $clusters = $inMerchantMode ? $this->merchantClusters($filters) : null;

        // An expansion outlives the page it was made on: $expandedKey is a URL
        // property and nothing clears it when the cluster paginator moves. The
        // cluster loop renders rows only for a cluster on the current page, so
        // members loaded for an off-page expansion would hand the page-level
        // control a set of ids with no checkboxes behind them — "Select these
        // 3" over rows the user cannot see, writing off screen when clicked.
        $expandedOnPage = $clusters !== null && $this->expandedOnPage($clusters);

        // The rows a page-level "select all" would affect: the current page in
        // date mode, the expanded cluster in merchant mode.
        if ($inMerchantMode) {
            $clusterRows = $expandedOnPage ? $this->clusterMembers($filters) : new EloquentCollection;
            $visibleRows = $clusterRows;
        } else {
            $clusterRows = null;
            $visibleRows = $transactions->getCollection();
        }

        $pageEligibleIds = $this->eligibleIds($visibleRows);
        $scopeCoversScreen = $this->scopeCoversScreen($expandedOnPage);

        // A scope is not an id list, but a checkbox binds to $selected, so the
        // rows it covers have to be mirrored into that property or they render
        // unticked beside a bar reporting hundreds. Mirrored on every render
        // rather than once at selection time: the page, the expansion and the
        // filters can all move under a held scope, and a stale mirror would
        // leave the last screen's ids sitting in a property the next hand-pick
        // reads.
        if ($this->bulkScope !== null) {
            $this->selected = $scopeCoversScreen
                ? array_fill_keys(array_diff($pageEligibleIds, $this->bulkExcluded), true)
                : [];
        }

        [$merchantBrands, $identifiableIds] = $this->merchantBrandState($visibleRows);

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'grouped' => $grouped,
            'inMerchantMode' => $inMerchantMode,
            'clusters' => $clusters,
            'clusterRows' => $clusterRows,
            'accounts' => $accounts,
            'categoryName' => $this->category
                ? Category::query()->whereKey($this->category)->value('name')
                : null,
            'formatMoney' => MoneyCast::format(...),
            'periodLabel' => $periodEnum->label(),
            'hasPayCycle' => auth()->user()->hasPayCycleConfigured(),
            'showCustomRange' => $periodEnum === TransactionPeriod::Custom,
            'gmailEnabled' => app(GmailServiceContract::class)->isConfigured(),
            'splitCategories' => Category::visibleSortedByFullPath(),
            'selectionCount' => $this->selectionCount(),
            'pageEligibleIds' => $pageEligibleIds,
            'matchingCount' => $this->matchingEligibleCount(),
            'merchantBrands' => $merchantBrands,
            'identifiableIds' => $identifiableIds,
            'creditSummary' => $this->creditSummary(),
        ]);
    }

    /**
     * The Context.dev balance and today's remaining allowance, or null when
     * enrichment is off. A stale balance is refreshed with the free logs call;
     * if that fails the last recorded value is kept (null when there is none).
     *
     * @return array{balance: int|null, leftToday: int}|null
     */
    private function creditSummary(): ?array
    {
        if (! config('services.context_dev.enrichment_enabled')) {
            return null;
        }

        $balance = app(ContextDevCreditBalance::class);

        if ($balance->isStale()) {
            try {
                app(ContextDevServiceContract::class)->creditsRemaining();
            } catch (ContextDevException $e) {
                Log::warning('Context.dev credit balance refresh failed', ['exception' => $e::class]);
            }
        }

        return [
            'balance' => $balance->current()['remaining'] ?? null,
            'leftToday' => app(ContextDevCreditBudget::class)->remaining(),
        ];
    }

    /**
     * For the rows on screen: the resolved brand per transaction id, and the ids
     * of rows that may offer "Identify merchant" (enrichment on, a lookup still
     * affordable today, gate passes, no resolved, vetoed or still-fresh sidecar
     * row). Keys are derived with merchantKeyFor() for display and veto; identify
     * is offered only when the column is persisted, because the job selects rows
     * by it. One query per render, not per row.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array{0: array<int, MerchantBrand>, 1: array<int, true>}
     */
    private function merchantBrandState(Collection $rows): array
    {
        $keyById = $rows->mapWithKeys(fn (Transaction $row): array => [$row->id => $this->merchantKeyFor($row)]);

        if ($keyById->isEmpty()) {
            return [[], []];
        }

        $sidecar = MerchantBrand::query()
            ->where('user_id', auth()->id())
            ->whereIn('merchant_key', $keyById->unique()->values())
            ->get()
            ->keyBy('merchant_key');

        $enrichmentEnabled = (bool) config('services.context_dev.enrichment_enabled')
            && app(ContextDevCreditBudget::class)->remaining() >= ContextDevCreditBudget::BRAND_LOOKUP_CREDITS;
        $gate = app(DescriptorGate::class);
        $brands = [];
        $identifiable = [];

        foreach ($rows as $row) {
            $existing = $sidecar->get($keyById[$row->id]);

            if ($existing?->status === MerchantBrandStatus::Resolved) {
                $brands[$row->id] = $existing;
            } elseif ($enrichmentEnabled && $row->merchant_key !== null && ! $existing?->blocksLookup() && $gate->allows($row)) {
                $identifiable[$row->id] = true;
            }
        }

        return [$brands, $identifiable];
    }

    private function merchantKeyFor(Transaction $transaction): string
    {
        return $transaction->merchant_key ?? $transaction->resolveMerchantKey();
    }

    /**
     * Fold the checkbox state the client just sent into the held scope: render()
     * mirrors the scope into $selected for the rows on screen, so anything
     * visible and no longer ticked is a subtraction, and a re-tick undoes one.
     */
    private function applyVisibleTicksToScope(): void
    {
        $excluded = array_flip($this->bulkExcluded);

        foreach ($this->visibleEligibleIds() as $id) {
            if (empty($this->selected[$id])) {
                $excluded[$id] = true;
            } else {
                unset($excluded[$id]);
            }
        }

        $this->bulkExcluded = array_map(intval(...), array_keys($excluded));

        // Subtracting the last row leaves a scope that resolves to nothing;
        // holding it would keep a bulk bar alive over an empty selection.
        if ($this->selectionCount() === 0) {
            $this->clearSelection();
        }
    }

    /**
     * Merchant clustering is a sub-mode of uncategorised triage, never an
     * independent axis. Deriving it from both properties means no single
     * missed mutation path can put the page into a mode whose toggle the view
     * is not rendering.
     */
    private function inMerchantMode(): bool
    {
        return $this->groupMode === 'merchant' && $this->categorised === 'uncategorised';
    }

    /**
     * Whether the held selection scope actually covers the rows on screen.
     *
     * A scope is resolved from a snapshot rather than an id list, so the view
     * has to fold it into its none/some/all state. Folding it in
     * unconditionally is wrong in three reachable ways, because nothing clears
     * a scope when the view moves: toggleCluster() can expand a different
     * merchant, the cluster paginator can move off the expanded cluster
     * entirely, and any filter change leaves the frozen snapshot standing
     * (deliberately — the snapshot is what gets written). In each case the
     * control would claim a selection over rows that are not in it, and the
     * click would call toggleVisible(), which nulls the scope and destroys the
     * real selection.
     *
     * Lives here rather than in Blade so the snapshot comparison stays next to
     * the one in selectionCount() and the view does not reach into the shape of
     * $bulkScope.
     *
     * @param  bool  $expandedOnPage  whether the expanded cluster is among the
     *                                clusters the current page renders
     */
    private function scopeCoversScreen(bool $expandedOnPage): bool
    {
        if ($this->bulkScope === null) {
            return false;
        }

        if (($this->bulkScope['filters'] ?? null) !== $this->currentFilters()) {
            return false;
        }

        $merchantKey = $this->bulkScope['merchantKey'] ?? null;

        return $merchantKey === null
            || ($merchantKey === $this->expandedKey && $expandedOnPage);
    }

    /**
     * Whether the expanded cluster is one of the clusters this page renders.
     *
     * The rows are aggregate projections hydrated as Transaction models, which
     * is what merchantClusters() returns and what PHPStan infers from it.
     *
     * @param  LengthAwarePaginator<int, Transaction>  $clusters
     */
    private function expandedOnPage(LengthAwarePaginator $clusters): bool
    {
        if ($this->expandedKey === null) {
            return false;
        }

        return array_any(
            $clusters->items(),
            fn (Transaction $cluster): bool => $cluster->merchant_key === $this->expandedKey,
        );
    }

    /**
     * The ordered, paginated rows date mode renders.
     *
     * Extracted so the page definition has one home: selectAllMatching() has to
     * resolve the same rows to materialise a scope, and a second copy of the
     * ordering would let the two drift.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    private function paginatedRows(array $filters): LengthAwarePaginator
    {
        return $this->queryFor($filters)
            ->withRelations()
            ->with(['emails', 'splits.category'])
            ->orderBy(
                in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'post_date',
                in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : 'desc',
            )
            ->paginate(25);
    }

    /**
     * Takes the base collection: LengthAwarePaginator::getCollection() is
     * declared as one even though it holds the page's Eloquent models.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return list<int>
     */
    private function eligibleIds(Collection $rows): array
    {
        return $rows
            ->reject(fn (Transaction $t): bool => $this->isBulkExcluded($t))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The eligible row ids currently rendered, resolved outside render().
     *
     * Takes the same page-presence test render() applies: an expansion left
     * behind by the cluster paginator renders no checkboxes, so its members
     * are not on screen and must not be folded into a scope.
     *
     * @return list<int>
     */
    private function visibleEligibleIds(): array
    {
        $filters = $this->currentFilters();

        if ($this->inMerchantMode()) {
            return $this->expandedOnPageNow()
                ? $this->eligibleIds($this->clusterMembers($filters))
                : [];
        }

        return $this->eligibleIds($this->paginatedRows($filters)->getCollection());
    }

    /**
     * Whether the expanded cluster is on the current cluster page, resolved
     * outside render() for the property hooks.
     */
    private function expandedOnPageNow(): bool
    {
        return $this->inMerchantMode()
            && $this->expandedOnPage($this->merchantClusters($this->currentFilters()));
    }

    /**
     * scopeCoversScreen() for callers that have not already resolved the
     * cluster page — the selection hooks, which run before render().
     */
    private function scopeCoversVisibleRows(): bool
    {
        return $this->scopeCoversScreen($this->expandedOnPageNow());
    }

    /**
     * One representative transaction for the selection, used as the rule's
     * source. A transfer can never be one: TransactionModal already refuses to
     * build a rule from a transfer and the same holds here.
     */
    private function ruleSourceTransaction(): ?Transaction
    {
        return $this->selectionQuery()?->orderBy('id')->first();
    }

    /**
     * The chosen bulk category, or null with bulkError saying why. Shared by
     * the two actions that write it, so "apply" and "create rule" refuse the
     * same inputs with the same message.
     */
    private function chosenBulkCategoryId(): ?int
    {
        $categoryId = (int) $this->bulkCategoryId;

        if ($categoryId <= 0) {
            $this->bulkError = 'Choose a category first.';

            return null;
        }

        if (! Category::visible()->whereKey($categoryId)->exists()) {
            $this->bulkError = 'That category is not available.';

            return null;
        }

        return $categoryId;
    }

    /**
     * Recompute the held preview. Null — the panel's "choose a category and a
     * match value" state — until both are usable: a preview against no
     * category would count rows the confirm button then refuses to write.
     */
    private function refreshRulePreview(): void
    {
        $this->rulePreview = null;

        if (! $this->rulePanelOpen || mb_trim($this->ruleMatchValue) === '') {
            return;
        }

        $categoryId = (int) $this->bulkCategoryId;

        if ($categoryId <= 0 || ! Category::visible()->whereKey($categoryId)->exists()) {
            return;
        }

        $source = $this->ruleSourceTransaction();

        if ($source === null) {
            return;
        }

        $this->rulePreview = app(CategoryRuleGenerator::class)
            ->preview($source, $categoryId, $this->ruleMatchValue, $this->resolvedSelectionIds());
    }

    /**
     * Every row the selection resolves to, whatever is on screen.
     *
     * Not selectedIds(): under a held scope that is only the page's mirror of
     * it — empty for a collapsed cluster — so the preview would call the
     * user's own selection "outside your selection".
     *
     * @return list<int>
     */
    private function resolvedSelectionIds(): array
    {
        return $this->selectionQuery()
            ?->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all() ?? [];
    }

    /**
     * The resolved selection, re-authorised against the signed-in user.
     *
     * Both branches are independently user-scoped: the snapshot branch through
     * queryFor(), the hand-picked branch through its own
     * where('user_id', auth()->id())->current(). A forged id therefore matches
     * no rows rather than reaching another account.
     *
     * Returns null when the selection cannot be resolved to a definite set:
     * nothing hand-picked, or a scope whose filters are not an array or whose
     * merchantKey is present but not a string. A malformed scope is refused
     * rather than silently widened — dropping the merchant predicate would turn
     * "this cluster" into "every row matching the filters", and falling back to
     * an empty filter set would turn it into "every row this month".
     *
     * @return Builder<Transaction>|null
     */
    private function selectionQuery(): ?Builder
    {
        if ($this->bulkScope !== null) {
            $filters = $this->bulkScope['filters'] ?? null;
            $merchantKey = $this->bulkScope['merchantKey'] ?? null;

            if (! is_array($filters) || ($merchantKey !== null && ! is_string($merchantKey))) {
                return null;
            }

            return $this->eligibleForBulk($this->queryFor($filters))
                ->when($merchantKey !== null, fn (Builder $q): Builder => $q->where('merchant_key', $merchantKey))
                // Rows the user took back out of the scope. A scope minus its
                // exclusions is what "everything matching this, except those"
                // resolves to, and it is the same set the bar counts.
                ->when($this->bulkExcluded !== [], fn (Builder $q): Builder => $q->whereNotIn('id', $this->bulkExcluded));
        }

        $ids = $this->selectedIds();

        if ($ids === []) {
            return null;
        }

        return $this->eligibleForBulk(
            Transaction::query()->where('user_id', auth()->id())->current(),
        )->whereIn('id', $ids);
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        $ids = [];

        foreach ($this->selected as $id => $isSelected) {
            if ($isSelected) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * How many rows the current selection would actually write.
     *
     * Always counted from the database, including for hand-picked ids: a row
     * that was ticked and then split becomes ineligible while its id stays in
     * $selected, so counting ticks would overstate the reach.
     */
    private function selectionCount(): int
    {
        // A whole-filter scope with nothing subtracted resolves to exactly the
        // query matchingCount already ran this render, so reuse it instead of
        // repeating the aggregate with its two NOT EXISTS subqueries. An
        // exclusion list makes the two sets differ, so the shortcut is off.
        if ($this->bulkScope !== null
            && $this->bulkExcluded === []
            && ($this->bulkScope['merchantKey'] ?? null) === null
            && ($this->bulkScope['filters'] ?? null) === $this->currentFilters()) {
            return $this->matchingEligibleCount();
        }

        return $this->selectionQuery()?->count() ?? 0;
    }

    /**
     * How many rows "select all matching this filter" would cover, shown on the
     * affordance itself so the user sees the real number before clicking.
     *
     * Memoised for the render pass: render() needs it for the affordance and
     * selectionCount() reuses it for a whole-filter scope.
     */
    private function matchingEligibleCount(): int
    {
        return $this->matchingCountCache ??= $this->eligibleForBulk(
            $this->queryFor($this->currentFilters()),
        )->count();
    }

    /**
     * The filter values currently bound to the component, as a plain array that
     * can be frozen into a bulk-selection scope and re-resolved later.
     *
     * clusterableOnly rides along so a snapshot taken in merchant mode keeps
     * excluding rows that no cluster can render, exactly as the on-screen
     * aggregate does.
     *
     * @return array<string, mixed>
     */
    private function currentFilters(): array
    {
        return [
            'direction' => $this->direction,
            'account' => $this->account,
            'category' => $this->category,
            'planned' => $this->planned,
            'categorised' => $this->categorised,
            'source' => $this->source,
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'clusterableOnly' => $this->inMerchantMode(),
        ];
    }

    /**
     * Every filter the page exposes, with no ordering or eager loading, so the
     * row list, the cluster aggregate and a bulk write cannot drift apart.
     *
     * Takes the filter set explicitly rather than reading $this, because a
     * "select all matching this filter" action stores a snapshot and must be
     * re-resolved against that snapshot at commit time — not against whatever
     * the user has since changed the filters to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function queryFor(array $filters): Builder
    {
        $period = is_string($filters['period'] ?? null) ? $filters['period'] : 'this-month';
        $periodEnum = TransactionPeriod::tryFrom($period) ?? TransactionPeriod::ThisMonth;
        $from = is_string($filters['from'] ?? null) ? $filters['from'] : null;
        $to = is_string($filters['to'] ?? null) ? $filters['to'] : null;
        $dates = $periodEnum->dateRange(auth()->user(), $from, $to);

        $directionEnum = match ($filters['direction'] ?? 'all') {
            'incoming' => TransactionDirection::Credit,
            'outgoing' => TransactionDirection::Debit,
            default => null,
        };

        return Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->when($directionEnum, fn ($q, $dir) => $q->where('direction', $dir))
            // Merchant mode renders clusters, and a NULL merchant_key can never
            // be one, so a count or a bulk write taken in that mode must not
            // reach rows the page is incapable of showing.
            ->when(($filters['clusterableOnly'] ?? false) === true, fn ($q) => $q->whereNotNull('merchant_key'))
            ->when(is_scalar($filters['account'] ?? null) ? $filters['account'] : null, fn ($q, $id) => $q->where('account_id', $id))
            ->when(is_scalar($filters['category'] ?? null) ? $filters['category'] : null, fn ($q, $id) => $q->where(fn ($q) => $q
                ->where('category_id', $id)
                ->orWhereHas('splits', fn ($s) => $s->where('category_id', $id))))
            ->when(($filters['planned'] ?? null) === 'planned', fn ($q) => $q->whereNotNull('planned_transaction_id'))
            ->when(($filters['planned'] ?? null) === 'unplanned', fn ($q) => $q->whereNull('planned_transaction_id'))
            ->when(($filters['categorised'] ?? null) === 'categorised', fn ($q) => $q->where(fn ($q) => $q
                ->whereNotNull('category_id')
                ->orWhereHas('splits')))
            ->when(($filters['categorised'] ?? null) === 'uncategorised', fn ($q) => $q
                ->whereNull('category_id')
                ->whereDoesntHave('splits'))
            ->when(($filters['source'] ?? null) === 'manual', fn ($q) => $q
                ->where('category_source', CategorySource::Manual->value))
            ->when(($filters['source'] ?? null) === 'rule', fn ($q) => $q
                ->where('category_source', CategorySource::Rule->value))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('clean_description', 'like', "%{$term}%")
                    ->orWhere('merchant_name', 'like', "%{$term}%");
            }))
            ->when($dates['start'], fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($dates['end'], fn ($q, $e) => $q->where('post_date', '<=', $e));
    }

    /**
     * Rows a bulk categorisation may legally touch.
     *
     * A split transaction's category is decided by its parts, and a transfer is
     * not spending at all. The transfer test is transfer_pair_id (the pairing
     * column); parent_transaction_id is createChild() lineage, a different
     * relationship that would exclude the wrong rows entirely. The split test
     * is the splits relation, mirrored for an already-loaded model by
     * isBulkExcluded().
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function eligibleForBulk(Builder $query): Builder
    {
        return $query
            ->whereNull('transfer_pair_id')
            ->whereDoesntHave('splits');
    }

    /**
     * One page of merchant clusters, biggest first.
     *
     * Aggregating in SQL is the whole point of persisting merchant_key: grouping
     * in PHP would either split a cluster across pages (group after paginate) or
     * load every matching row into memory on each render (group before it).
     *
     * The paginator is constructed by hand because the unit of pagination here
     * is a cluster, not a row — and because Collection has no paginate().
     *
     * Items are Transaction models hydrated with only the aggregate columns,
     * which is what Eloquent returns for a grouped selectRaw. They are read-only
     * cluster summaries, never saved: `row_count`, `eligible_count`,
     * `total_amount`, `min_amount`, `max_amount`, `first_date`, `last_date`,
     * `account_count` and `direction_count` are raw attributes, deliberately
     * named apart from `amount` so the MoneyCast on that column does not apply
     * to them.
     *
     * Rows whose merchant_key has not been populated yet are excluded. The
     * column is nullable and the backfill is a deploy step, so a NULL group is
     * a real possibility — and it cannot be clustered: COUNT(DISTINCT) skips
     * NULL while GROUP BY emits it, so the total and the rows would disagree,
     * and `$expandedKey === null` is already this component's sentinel for
     * "nothing expanded", making a null cluster key unrepresentable.
     *
     * queryFor() already applies the same guard whenever the filter set carries
     * clusterableOnly, which is how the bulk count and a frozen scope stay in
     * step with what is on screen. It is repeated here unconditionally so the
     * aggregate is correct even if called with a filter set that lacks the
     * flag; the date list, whose filters never carry it, keeps every row.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    private function merchantClusters(array $filters): LengthAwarePaginator
    {
        // Cast before max(): Livewire stores the raw ?page query value, and
        // max(1, 'abc') returns the string, which forPage() cannot subtract.
        $page = max(1, (int) $this->getPage());

        // One closure for both queries so the count and the rows can never
        // disagree about which rows are clusterable.
        $clusterable = fn (): Builder => $this->queryFor($filters)
            ->whereNotNull('merchant_key');

        $total = $clusterable()
            ->distinct()
            ->count('merchant_key');

        $rows = $clusterable()
            ->selectRaw(implode(', ', [
                'merchant_key',
                'COUNT(*) as row_count',
                // What "select all in this merchant" can actually write.
                // row_count counts everything in the cluster, but transfers
                // cluster too (both legs share a description, so they share a
                // merchant_key) and eligibleForBulk() refuses them, so a button
                // labelled from row_count would promise rows it cannot touch.
                // Splits are already excluded upstream by the uncategorised
                // filter's whereDoesntHave('splits').
                'SUM(CASE WHEN transfer_pair_id IS NULL THEN 1 ELSE 0 END) as eligible_count',
                'SUM(amount) as total_amount',
                // Spread is measured on magnitudes so a cluster holding both a
                // refund and a purchase reports a real range, not a sign flip.
                'MIN(ABS(amount)) as min_amount',
                'MAX(ABS(amount)) as max_amount',
                'MIN(post_date) as first_date',
                'MAX(post_date) as last_date',
                'COUNT(DISTINCT account_id) as account_count',
                'COUNT(DISTINCT direction) as direction_count',
            ]))
            ->groupBy('merchant_key')
            ->orderByDesc('row_count')
            ->orderBy('merchant_key')
            ->forPage($page, self::CLUSTERS_PER_PAGE)
            ->get();

        return new LengthAwarePaginator(
            $rows,
            $total,
            self::CLUSTERS_PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * Member rows of the one expanded cluster. Nothing is loaded for collapsed
     * clusters — a page of 25 clusters could otherwise pull thousands of rows.
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, Transaction>
     */
    private function clusterMembers(array $filters): EloquentCollection
    {
        if ($this->expandedKey === null) {
            // No query at all: nothing is expanded, so there is nothing to load.
            return new EloquentCollection;
        }

        return $this->queryFor($filters)
            ->where('merchant_key', $this->expandedKey)
            ->withRelations()
            ->with(['emails', 'splits.category'])
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->get();
    }

    private function closeSplitPanel(): void
    {
        $this->splitPanelTxnId = null;
        $this->splitLines = [];
        $this->splitError = null;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    private function restoreRememberedPeriod(): void
    {
        if (request()->query('period') !== null) {
            return;
        }

        $period = session()->get('transactions.period');

        if (! is_string($period)) {
            return;
        }

        $this->period = $period;

        if (request()->query('from') === null) {
            $from = session()->get('transactions.from');
            $this->from = is_string($from) ? $from : null;
        }

        if (request()->query('to') === null) {
            $to = session()->get('transactions.to');
            $this->to = is_string($to) ? $to : null;
        }
    }
}
