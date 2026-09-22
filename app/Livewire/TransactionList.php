<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Exceptions\GmailSearchException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\TransactionSplit;
use App\Services\GmailService;
use App\Support\AmountParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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

    public string $bulkCategoryId = '';

    #[Locked]
    public ?string $bulkError = null;

    #[Locked]
    public ?string $bulkNotice = null;

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
     * Hand-picking a row means the user is no longer working from a filter-wide
     * scope, so the scope is dropped rather than silently widening the write.
     */
    public function updatedSelected(): void
    {
        $this->bulkScope = null;
        $this->bulkError = null;
        $this->bulkNotice = null;
    }

    /**
     * Tick or untick every eligible row currently on screen: the page in date
     * mode, the expanded cluster in merchant mode.
     *
     * @param  list<int>  $ids
     */
    public function toggleVisible(array $ids, bool $select): void
    {
        $this->bulkScope = null;
        // Same reset as updatedSelected(): the notice is a persistent inline
        // element, not a toast, so without this a fresh selection renders
        // directly beneath "Categorised 25 transactions." from the previous
        // apply and reads as though it has already been written.
        $this->bulkError = null;
        $this->bulkNotice = null;

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
        $this->selected = [];
        $this->bulkScope = ['filters' => $this->currentFilters(), 'merchantKey' => null];
        $this->bulkError = null;
        $this->bulkNotice = null;
    }

    /**
     * Select every eligible row in one cluster, however many pages it spans.
     * Stored as a scope rather than an id list: a big cluster would otherwise
     * push hundreds of ids through every subsequent request.
     */
    public function selectCluster(string $merchantKey): void
    {
        $this->selected = [];
        $this->bulkScope = ['filters' => $this->currentFilters(), 'merchantKey' => $merchantKey];
        $this->bulkError = null;
        $this->bulkNotice = null;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->bulkScope = null;
        $this->bulkCategoryId = '';
        $this->bulkError = null;
        $this->bulkNotice = null;
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
     */
    public function applyCategoryToSelection(): void
    {
        $this->bulkError = null;
        $this->bulkNotice = null;

        $categoryId = (int) $this->bulkCategoryId;

        if ($categoryId <= 0) {
            $this->bulkError = 'Choose a category first.';

            return;
        }

        if (! Category::visible()->whereKey($categoryId)->exists()) {
            $this->bulkError = 'That category is not available.';

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
        $updated = (int) (clone $query)
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
        DB::transaction(function () use ($query, $categoryId): void {
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
     * render() query picks up the change.
     */
    #[On('transaction-saved')]
    public function refreshList(): void {}

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
        $transactions = $inMerchantMode
            ? null
            : $this->queryFor($filters)
                ->withRelations()
                ->with(['emails', 'splits.category'])
                ->orderBy(
                    in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'post_date',
                    in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : 'desc',
                )
                ->paginate(25);

        $accounts = Account::query()
            ->where('user_id', auth()->id())
            ->active()
            ->get(['id', 'name']);

        $grouped = $transactions === null
            ? collect()
            : $transactions->getCollection()
                ->groupBy(static fn (Transaction $t): string => $t->post_date->format('Y-m-d'));

        $clusterRows = $inMerchantMode ? $this->clusterMembers($filters) : null;

        // The rows a page-level "select all" would affect: the current page in
        // date mode, the expanded cluster in merchant mode.
        $visibleRows = $transactions === null
            ? ($clusterRows ?? new EloquentCollection)
            : $transactions->getCollection();

        $pageEligibleIds = $visibleRows
            ->reject(fn (Transaction $t): bool => $this->isBulkExcluded($t))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'grouped' => $grouped,
            'inMerchantMode' => $inMerchantMode,
            'clusters' => $inMerchantMode ? $this->merchantClusters($filters) : null,
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
            'scopeCoversScreen' => $this->scopeCoversScreen(),
        ]);
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
     * A scope stores $selected = [] by design, so the view has to fold it into
     * its none/some/all state or every checkbox would render unticked beside a
     * bulk bar reporting hundreds. Folding it in unconditionally is wrong in
     * two reachable ways, because nothing clears a scope when the view moves:
     * toggleCluster() can expand a different merchant, and any filter change
     * leaves the frozen snapshot standing (deliberately — the snapshot is what
     * gets written). In both cases the control would claim a selection over
     * rows that are not in it, and the click would call toggleVisible(), which
     * nulls the scope and destroys the real selection.
     *
     * Lives here rather than in Blade so the snapshot comparison stays next to
     * the one in selectionCount() and the view does not reach into the shape of
     * $bulkScope.
     */
    private function scopeCoversScreen(): bool
    {
        if ($this->bulkScope === null) {
            return false;
        }

        if (($this->bulkScope['filters'] ?? null) !== $this->currentFilters()) {
            return false;
        }

        $merchantKey = $this->bulkScope['merchantKey'] ?? null;

        return $merchantKey === null || $merchantKey === $this->expandedKey;
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
                ->when($merchantKey !== null, fn (Builder $q): Builder => $q->where('merchant_key', $merchantKey));
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
        // A whole-filter scope resolves to exactly the query matchingCount
        // already ran this render, so reuse it instead of repeating the
        // aggregate with its two NOT EXISTS subqueries.
        if ($this->bulkScope !== null
            && ($this->bulkScope['merchantKey'] ?? null) === null
            && ($this->bulkScope['filters'] ?? null) === $this->currentFilters()) {
            return $this->matchingEligibleCount();
        }

        return (int) ($this->selectionQuery()?->count() ?? 0);
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
        return $this->matchingCountCache ??= (int) $this->eligibleForBulk(
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
