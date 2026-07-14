<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionList extends Component
{
    use WithPagination;

    private const array SORTABLE_COLUMNS = ['post_date', 'amount', 'description'];

    private const array VALID_DIRECTIONS = ['all', 'incoming', 'outgoing'];

    private const array VALID_PLANNED_FILTERS = ['all', 'planned', 'unplanned'];

    private const array VALID_CATEGORISED_FILTERS = ['all', 'categorised', 'uncategorised'];

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
                    'category_id' => $split->category_id,
                    'amount' => number_format(abs((int) $split->amount) / 100, 2, '.', ''),
                    'notes' => $split->notes ?? '',
                ])
                ->all();

            return;
        }

        $this->splitLines = [
            [
                'category_id' => $transaction->category_id,
                'amount' => number_format(abs((int) $transaction->amount) / 100, 2, '.', ''),
                'notes' => '',
            ],
            ['category_id' => null, 'amount' => '0.00', 'notes' => ''],
        ];
    }

    public function addSplitLine(): void
    {
        if ($this->splitPanelTxnId === null) {
            return;
        }

        $this->splitLines[] = ['category_id' => null, 'amount' => '0.00', 'notes' => ''];
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

            if ($categoryId === null) {
                $this->splitError = 'Every split line needs a category.';

                return;
            }

            if ($cents <= 0) {
                $this->splitError = 'Every split line needs an amount greater than zero.';

                return;
            }

            $notes = is_string($line['notes'] ?? null) ? mb_trim($line['notes']) : '';

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
        $dates = $periodEnum->dateRange(auth()->user(), $this->from, $this->to);

        $directionEnum = match ($this->direction) {
            'incoming' => TransactionDirection::Credit,
            'outgoing' => TransactionDirection::Debit,
            default => null,
        };

        $transactions = Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->when($directionEnum, fn ($q, $dir) => $q->where('direction', $dir))
            ->when($this->account, fn ($q, $id) => $q->where('account_id', $id))
            ->when($this->category, fn ($q, $id) => $q->where(fn ($q) => $q
                ->where('category_id', $id)
                ->orWhereHas('splits', fn ($s) => $s->where('category_id', $id))))
            ->when($this->planned === 'planned', fn ($q) => $q->whereNotNull('planned_transaction_id'))
            ->when($this->planned === 'unplanned', fn ($q) => $q->whereNull('planned_transaction_id'))
            ->when($this->categorised === 'categorised', fn ($q) => $q->where(fn ($q) => $q
                ->whereNotNull('category_id')
                ->orWhereHas('splits')))
            ->when($this->categorised === 'uncategorised', fn ($q) => $q
                ->whereNull('category_id')
                ->whereDoesntHave('splits'))
            ->when($this->search, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('clean_description', 'like', "%{$term}%")
                    ->orWhere('merchant_name', 'like', "%{$term}%");
            }))
            ->when($dates['start'], fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($dates['end'], fn ($q, $e) => $q->where('post_date', '<=', $e))
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

        $grouped = $transactions->getCollection()
            ->groupBy(static fn (Transaction $t): string => $t->post_date->format('Y-m-d'));

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'grouped' => $grouped,
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
        ]);
    }

    private function closeSplitPanel(): void
    {
        $this->splitPanelTxnId = null;
        $this->splitLines = [];
        $this->splitError = null;
    }

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
