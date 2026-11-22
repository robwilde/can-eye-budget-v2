<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Account;
use App\Models\Category;
use App\Models\Document;
use App\Models\Merchant;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Models\UserRule;
use BackedEnum;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * or vector store ids. A split transaction is written as its parts, not the parent, and amounts
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
     * @return Generator<int, array<string, string>>
     */
    private function records(Builder $query, array $columns): Generator
    {
        foreach ($query->orderBy('id')->lazyById(self::CHUNK) as $model) {
            yield $this->row($model, $columns);
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function row(Model $model, array $columns): array
    {
        $row = [];

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);
            $row[$column] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value instanceof BackedEnum => (string) $value->value,
                default => (string) $value,
            };
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
