<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * One-time wizard: every upstream Redbark account is either created as a new app
 * account, linked to an existing one, or skipped for good.
 *
 * Linking to an existing account is the whole point of that option — it keeps the
 * history already on that account instead of starting a parallel one beside it.
 */
final class RedbarkAccountSetup extends Component
{
    /** @var array<int, string> keyed by RedbarkAccount id */
    public array $choices = [];

    /** @var list<string> */
    public array $rowErrors = [];

    public function mount(
        RedbarkClientFactory $factory,
        TransactionIngestor $ingestor,
        RedbarkTransactionMatcher $matcher,
    ): void {
        $feed = $this->feed();

        if ($feed === null) {
            $this->redirect(route('providers.edit'), navigate: true);

            return;
        }

        // Straight after saving a key the queued sync may not have run yet, which would
        // leave the wizard empty and useless. Fetch inline once.
        if ($feed->last_synced_at === null && ! $this->redbarkAccounts()->isNotEmpty()) {
            try {
                (new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual))->handle($factory, $ingestor, $matcher);
            } catch (Throwable $e) {
                $this->rowErrors[] = $e->getMessage();
            }

            unset($this->redbarkAccounts);
        }

        foreach ($this->redbarkAccounts() as $redbarkAccount) {
            $this->choices[$redbarkAccount->id] ??= 'skip';
        }
    }

    /** @return Collection<int, RedbarkAccount> */
    #[Computed]
    public function redbarkAccounts(): Collection
    {
        $feed = $this->feed();

        if ($feed === null) {
            return new Collection;
        }

        return $feed->accounts()->needsSetup()->orderBy('name')->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function availableAccounts(): Collection
    {
        return Account::query()
            ->where('user_id', Auth::id())
            ->whereDoesntHave('redbarkAccount')
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        $feed = $this->feed();

        if ($feed === null) {
            $this->redirect(route('providers.edit'), navigate: true);

            return;
        }

        $this->rowErrors = [];
        $linked = 0;

        foreach ($this->choices as $redbarkAccountId => $choice) {
            $redbarkAccount = RedbarkAccount::query()->with('feed')->find($redbarkAccountId);

            if ($redbarkAccount === null) {
                continue;
            }

            abort_unless($redbarkAccount->feed->user_id === Auth::id(), 403);

            try {
                // Each row commits on its own so one bad choice cannot cost the user the
                // rest of the batch; the failures are reported back on the wizard.
                DB::transaction(function () use ($redbarkAccount, $choice, &$linked): void {
                    $linked += $this->applyChoice($redbarkAccount, $choice);
                });
            } catch (HttpException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->rowErrors[] = "{$redbarkAccount->name}: {$e->getMessage()}";
            }
        }

        $feed->update([
            'pending_account_setup' => $feed->accounts()->needsSetup()->exists(),
        ]);

        unset($this->redbarkAccounts, $this->availableAccounts);

        if ($this->rowErrors !== []) {
            return;
        }

        if ($linked > 0) {
            SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);
        }

        session()->flash('status', __('Redbark accounts set up.'));

        $this->redirect(route('accounts'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.redbark-account-setup', [
            'accountClasses' => [
                AccountClass::Transaction,
                AccountClass::Savings,
                AccountClass::CreditCard,
                AccountClass::Loan,
                AccountClass::Mortgage,
            ],
        ]);
    }

    private function feed(): ?RedbarkFeed
    {
        return RedbarkFeed::query()->where('user_id', Auth::id())->first();
    }

    /** @return int 1 when the row ended up linked to an app account */
    private function applyChoice(RedbarkAccount $redbarkAccount, string $choice): int
    {
        if ($choice === 'skip') {
            $redbarkAccount->update(['ignored' => true]);

            return 0;
        }

        if (str_starts_with($choice, 'existing:')) {
            $account = Account::query()->findOrFail((int) mb_substr($choice, mb_strlen('existing:')));

            abort_unless($account->user_id === Auth::id(), 403);

            if ($account->redbarkAccount()->exists()) {
                throw new RuntimeException(__('That account is already connected to Redbark.'));
            }

            $this->link($redbarkAccount, $account);

            return 1;
        }

        $class = str_starts_with($choice, 'new:')
            ? AccountClass::tryFrom(mb_substr($choice, mb_strlen('new:')))
            : null;

        if ($class === null) {
            throw new RuntimeException(__('Unrecognised choice for this account.'));
        }

        $this->link($redbarkAccount, Account::create([
            'user_id' => Auth::id(),
            'name' => $redbarkAccount->name,
            'type' => $class,
            'institution' => $redbarkAccount->institution_name ?? '',
            'currency' => $redbarkAccount->currency,
            'balance' => $redbarkAccount->current_balance ?? 0,
            'account_last4' => $redbarkAccount->account_number === null
                ? null
                : mb_substr($redbarkAccount->account_number, -4),
            'import_source' => ImportSource::Redbark,
            'group' => AccountGroup::DayToDay,
            'status' => AccountStatus::Active,
        ]));

        return 1;
    }

    private function link(RedbarkAccount $redbarkAccount, Account $account): void
    {
        $redbarkAccount->update(['account_id' => $account->id, 'ignored' => false]);
        $account->update(['import_source' => ImportSource::Redbark]);
    }
}
