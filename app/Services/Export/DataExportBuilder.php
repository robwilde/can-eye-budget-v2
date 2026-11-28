<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Document;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\GoalSpend;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\Payee;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Statement;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\TransferRule;
use App\Models\User;
use App\Models\UserRule;
use BackedEnum;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Writes a zip of one user's data: a CSV per record type, one NDJSON file with every record and a
 * manifest of attachments and documents (checksum and size, never the file bytes). Every query is
 * scoped to the user and read in chunks, and each file is written to disk as it goes, so a large
 * account is never held in memory. Only the listed columns are exported: no credentials, tokens
 * or vector store ids, no stored file paths, and no Gmail message ids or links (also inside
 * an order event's payload) nor the email body (snippet or parsed payload) a BNPL order was read from; its subject line and date are kept. A record kind owned through its parent (goal accounts, pledges
 * and spends, occurrences, order events, tag links) is read by the parent's user id. A split transaction is written as its parts, not the parent, and amounts
 * are decimal strings derived from integer cents. Text cells that could run as a spreadsheet
 * formula are escaped.
 */
final class DataExportBuilder
{
    private const int CHUNK = 500;

    private const array ACCOUNT_COLUMNS = ['id', 'name', 'type', 'institution', 'currency', 'status', 'description'];

    private const array CATEGORY_COLUMNS = ['id', 'name', 'parent_id', 'budget_tag', 'is_hidden'];

    private const array MERCHANT_COLUMNS = ['id', 'name', 'merchant_key', 'website'];

    private const array PAYEE_COLUMNS = ['id', 'merchant_key', 'merchant_name', 'status', 'confirmed_category_id', 'budget_tag', 'user_rule_id'];

    private const array RULE_COLUMNS = ['id', 'name', 'description', 'strict_mode', 'is_active', 'order'];

    private const array GOAL_COLUMNS = ['id', 'name', 'kind', 'status', 'target_date', 'target_months_of_expenses', 'colour', 'icon', 'notes', 'completed_at', 'archived_at'];

    private const array GOAL_MONEY = ['target_amount', 'frozen_saved_amount'];

    private const array GOAL_ACCOUNT_COLUMNS = ['id', 'goal_id', 'account_id'];

    private const array GOAL_ACCOUNT_MONEY = ['earmark_amount'];

    private const array GOAL_PLEDGE_COLUMNS = ['id', 'goal_id', 'account_id', 'status', 'expires_at', 'matched_transaction_id'];

    private const array GOAL_SPEND_COLUMNS = ['id', 'goal_id', 'account_id', 'transaction_id'];

    private const array BUDGET_PLAN_COLUMNS = ['id', 'period_start', 'period_end', 'initialised_at'];

    private const array BUDGET_PLAN_MONEY = ['total_amount', 'expected_income'];

    private const array BUDGET_COLUMNS = ['id', 'budget_plan_id', 'category_id', 'name', 'period', 'start_date', 'end_date', 'rollover_enabled'];

    private const array BUDGET_MONEY = ['limit_amount', 'rolled_over_amount'];

    private const array PLANNED_TRANSACTION_COLUMNS = [
        'id', 'account_id', 'transfer_to_account_id', 'category_id', 'direction', 'type', 'origin', 'description', 'merchant_key', 'payment_url', 'notes',
        'learned_aliases', 'autopay', 'start_date', 'frequency', 'recurrence_interval', 'days_of_month', 'weekend_adjustment', 'schedule_pinned', 'until_date',
        'end_after_count', 'renewal_on', 'trial_ends_on', 'cancelled_on', 'reminder_days', 'grace_days', 'amount_tolerance_bps', 'match_early_days',
        'match_late_days', 'amount_strategy', 'status', 'is_pay_cycle_income', 'last_generated_date',
    ];

    private const array PLANNED_OCCURRENCE_COLUMNS = ['id', 'planned_transaction_id', 'original_date', 'due_date', 'snoozed_until', 'status', 'closed_at', 'closed_manually'];

    private const array TAG_COLUMNS = ['id', 'name', 'colour'];

    private const array TRANSFER_RULE_COLUMNS = ['id', 'account_id', 'counterpart_account_id', 'description_pattern'];

    private const array BNPL_ORDER_COLUMNS = [
        'id', 'provider', 'retailer', 'order_ref', 'instalment_count', 'first_due_date', 'last_due_date', 'frequency', 'account_id', 'category_id',
        'card_last4', 'status', 'review_note', 'planned_transaction_id', 'subject', 'email_date', 'reviewed_at',
    ];

    private const array BNPL_ORDER_MONEY = ['total', 'instalment_amount'];

    private const array BNPL_EVENT_COLUMNS = ['id', 'bnpl_order_id', 'event', 'actor', 'created_at'];

    private const array BNPL_EVENT_MAILBOX_KEYS = ['message_id', 'gmail_message_id'];

    private const array STATEMENT_COLUMNS = [
        'id', 'account_id', 'suggested_account_id', 'suggestion_confidence', 'review_status', 'content_hash', 'original_filename', 'mime_type', 'byte_size',
        'file_type', 'institution_hint', 'account_name_hint', 'last_four_hint', 'period_start', 'period_end', 'currency',
    ];

    private const array STATEMENT_MONEY = ['opening_balance', 'closing_balance'];

    private const array MERCHANT_ALIAS_COLUMNS = ['id', 'merchant_id', 'merchant_key'];

    /**
     * @return string the path of the zip inside the given directory
     */
    public function build(User $user, string $directory): string
    {
        $files = [
            'accounts' => $this->accounts($user),
            'transactions' => $this->transactions($user),
            'categories' => $this->records(Category::query()->where('user_id', $user->id), self::CATEGORY_COLUMNS),
            'merchants' => $this->records(Merchant::query()->where('user_id', $user->id), self::MERCHANT_COLUMNS),
            'payees' => $this->records(Payee::query()->where('user_id', $user->id), self::PAYEE_COLUMNS),
            'rules' => $this->rules($user),
            'goals' => $this->records(Goal::query()->where('user_id', $user->id), self::GOAL_COLUMNS, self::GOAL_MONEY),
            'goal_accounts' => $this->records(GoalAccount::query()->whereIn('goal_id', $this->goalIds($user)), self::GOAL_ACCOUNT_COLUMNS, self::GOAL_ACCOUNT_MONEY),
            'goal_pledges' => $this->records(GoalPledge::query()->whereIn('goal_id', $this->goalIds($user)), self::GOAL_PLEDGE_COLUMNS, ['amount']),
            'goal_spends' => $this->records(GoalSpend::query()->whereIn('goal_id', $this->goalIds($user)), self::GOAL_SPEND_COLUMNS, ['amount']),
            'budget_plans' => $this->records(BudgetPlan::query()->where('user_id', $user->id), self::BUDGET_PLAN_COLUMNS, self::BUDGET_PLAN_MONEY),
            'budgets' => $this->records(Budget::query()->where('user_id', $user->id), self::BUDGET_COLUMNS, self::BUDGET_MONEY),
            'planned_transactions' => $this->records(PlannedTransaction::query()->where('user_id', $user->id), self::PLANNED_TRANSACTION_COLUMNS, ['amount']),
            'planned_occurrences' => $this->records(PlannedOccurrence::query()->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->select('id')), self::PLANNED_OCCURRENCE_COLUMNS, ['expected_amount']),
            'tags' => $this->records(Tag::query()->where('user_id', $user->id), self::TAG_COLUMNS),
            'tag_transaction' => $this->tagLinks($user),
            'transfer_rules' => $this->records(TransferRule::query()->where('user_id', $user->id), self::TRANSFER_RULE_COLUMNS),
            'bnpl_orders' => $this->records(BnplOrder::query()->where('user_id', $user->id), self::BNPL_ORDER_COLUMNS, self::BNPL_ORDER_MONEY),
            'bnpl_order_events' => $this->bnplEvents($user),
            'statements' => $this->records(Statement::query()->where('user_id', $user->id), self::STATEMENT_COLUMNS, self::STATEMENT_MONEY),
            'merchant_aliases' => $this->records(MerchantAlias::query()->where('user_id', $user->id), self::MERCHANT_ALIAS_COLUMNS),
        ];

        $zipPath = $directory.'/export.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The archive could not be created.');
        }

        $ndjson = fopen($directory.'/all.ndjson', 'wb');
        $escape = new EscapeFormula;

        foreach ($files as $name => $rows) {
            $csv = Writer::createFromPath($directory.'/'.$name.'.csv', 'w+');
            $header = false;

            foreach ($rows as $row) {
                if (! $header) {
                    $csv->insertOne(array_keys($row));
                    $header = true;
                }

                $csv->insertOne(array_map(fn (string $cell): string => is_numeric($cell) ? $cell : $escape->escapeRecord([$cell])[0], $row));
                fwrite($ndjson, json_encode(['type' => $name, 'data' => $row], JSON_THROW_ON_ERROR)."\n");
            }

            $csv = null;
            $zip->addFile($directory.'/'.$name.'.csv', $name.'.csv');
        }

        fclose($ndjson);
        file_put_contents($directory.'/manifest.json', json_encode($this->manifest($user), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $zip->addFile($directory.'/all.ndjson', 'all.ndjson');
        $zip->addFile($directory.'/manifest.json', 'manifest.json');

        if (! $zip->close()) {
            throw new RuntimeException('The archive could not be written.');
        }

        return $zipPath;
    }

    /**
     * @return Builder<Goal>
     */
    private function goalIds(User $user): Builder
    {
        return Goal::query()->where('user_id', $user->id)->select('id');
    }

    /**
     * An order's events with the Gmail message ids their payloads carry left out, as the order's own
     * Gmail columns are.
     *
     * @return Generator<int, array<string, string>>
     */
    private function bnplEvents(User $user): Generator
    {
        $query = BnplOrderEvent::query()->whereIn('bnpl_order_id', BnplOrder::query()->where('user_id', $user->id)->select('id'));

        foreach ($query->orderBy('id')->lazyById(self::CHUNK) as $event) {
            $row = $this->row($event, self::BNPL_EVENT_COLUMNS);

            yield [...$row, 'payload' => $event->payload === null ? '' : json_encode(Arr::except($event->payload, self::BNPL_EVENT_MAILBOX_KEYS), JSON_THROW_ON_ERROR)];
        }
    }

    /**
     * The tag-to-transaction links of the user's tags on the user's transactions. The link table has
     * no id, so it is paged on its composite key.
     *
     * @return Generator<int, array<string, string>>
     */
    private function tagLinks(User $user): Generator
    {
        $links = DB::table('tag_transaction')
            ->whereIn('tag_id', Tag::query()->where('user_id', $user->id)->select('id'))
            ->whereIn('transaction_id', Transaction::query()->where('user_id', $user->id)->select('id'))
            ->orderBy('transaction_id')
            ->orderBy('tag_id');

        foreach ($links->lazy(self::CHUNK) as $link) {
            yield ['tag_id' => (string) $link->tag_id, 'transaction_id' => (string) $link->transaction_id];
        }
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function accounts(User $user): Generator
    {
        foreach (Account::query()->where('user_id', $user->id)->orderBy('id')->lazyById(self::CHUNK) as $account) {
            yield [...$this->row($account, self::ACCOUNT_COLUMNS), 'balance' => $this->decimal((int) $account->getRawOriginal('balance'))];
        }
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function rules(User $user): Generator
    {
        foreach (UserRule::query()->where('user_id', $user->id)->orderBy('id')->lazyById(self::CHUNK) as $rule) {
            yield [
                ...$this->row($rule, self::RULE_COLUMNS),
                'triggers' => json_encode($rule->triggers, JSON_THROW_ON_ERROR),
                'actions' => json_encode($rule->actions, JSON_THROW_ON_ERROR),
            ];
        }
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function transactions(User $user): Generator
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->whereDoesntHave('splitChildren')
            ->with('splits');

        foreach ($query->orderBy('id')->lazyById(self::CHUNK) as $transaction) {
            $base = [
                'id' => (string) $transaction->id,
                'account_id' => (string) $transaction->account_id,
                'post_date' => $transaction->post_date->toDateString(),
                'description' => (string) $transaction->description,
                'notes' => (string) $transaction->notes,
                'direction' => $transaction->direction->value,
                'status' => $transaction->status->value,
                'currency' => (string) $transaction->currency,
                'exchange_rate' => (string) $transaction->exchange_rate,
            ];

            if ($transaction->splits->isEmpty()) {
                yield [...$base, 'part' => '', 'category_id' => (string) $transaction->category_id, 'amount' => $this->decimal((int) $transaction->amount)];

                continue;
            }

            foreach ($transaction->splits as $split) {
                yield [...$base, 'part' => (string) $split->position, 'category_id' => (string) $split->category_id, 'amount' => $this->decimal((int) $split->amount)];
            }
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $money  integer-cent columns, written last as decimal strings
     * @return Generator<int, array<string, string>>
     */
    private function records(Builder $query, array $columns, array $money = []): Generator
    {
        foreach ($query->orderBy('id')->lazyById(self::CHUNK) as $model) {
            yield $this->row($model, $columns, $money);
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $money
     * @return array<string, string>
     */
    private function row(Model $model, array $columns, array $money = []): array
    {
        $row = [];

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);
            $row[$column] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof CarbonInterface => str_contains((string) ($model->getCasts()[$column] ?? ''), 'datetime') ? $value->toIso8601String() : $value->toDateString(),
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                default => (string) $value,
            };
        }

        foreach ($money as $column) {
            $cents = $model->getRawOriginal($column);
            $row[$column] = $cents === null ? '' : $this->decimal((int) $cents);
        }

        return $row;
    }

    /**
     * @return array{attachments: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    private function manifest(User $user): array
    {
        $attachments = [];

        $query = TransactionAttachment::query()
            ->whereIn('transaction_id', Transaction::query()->where('user_id', $user->id)->select('id'))
            ->orderBy('id');

        foreach ($query->lazyById(self::CHUNK) as $attachment) {
            $attachments[] = [
                'transaction_id' => $attachment->transaction_id,
                'filename' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size_bytes,
                'checksum' => $this->checksum($attachment->disk, $attachment->path),
            ];
        }

        $documents = Document::query()->forUser($user)->orderBy('id')->get()->map(fn (Document $document): array => [
            'filename' => $document->filename,
            'mime_type' => $document->mime_type,
            'size' => $document->byte_size,
            'checksum' => $document->content_hash,
        ])->all();

        return ['attachments' => $attachments, 'documents' => $documents];
    }

    private function checksum(string $disk, string $path): ?string
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.mb_str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
