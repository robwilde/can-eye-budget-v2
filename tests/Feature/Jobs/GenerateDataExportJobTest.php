<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DataExportStatus;
use App\Jobs\GenerateDataExportJob;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\GoalSpend;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Statement;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\TransactionSplit;
use App\Models\TransferRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const EXPORT_TYPES = [
    'accounts', 'transactions', 'categories', 'merchants', 'payees', 'rules',
    'goals', 'goal_accounts', 'goal_pledges', 'goal_spends', 'budget_plans', 'budgets',
    'planned_transactions', 'planned_occurrences', 'tags', 'tag_transaction', 'transfer_rules',
    'bnpl_orders', 'bnpl_order_events', 'statements', 'merchant_aliases',
];

const NEW_EXPORT_TYPES = [
    'goals', 'goal_accounts', 'goal_pledges', 'goal_spends', 'budget_plans', 'budgets',
    'planned_transactions', 'planned_occurrences', 'tags', 'tag_transaction', 'transfer_rules',
    'bnpl_orders', 'bnpl_order_events', 'statements', 'merchant_aliases',
];

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function runExport(DataExport $export): DataExport
{
    app()->call([new GenerateDataExportJob($export), 'handle']);

    return $export->fresh();
}

/** @return array<string, string> */
function exportEntries(DataExport $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->stored_path));
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $entries;
}

it('writes the export zip with csvs, ndjson and a manifest', function () {
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id]);
    Storage::disk('local')->put('attachments/receipt.pdf', 'attachment bytes');
    TransactionAttachment::factory()->create(['transaction_id' => $transaction->id, 'disk' => 'local', 'path' => 'attachments/receipt.pdf', 'original_name' => 'receipt.pdf', 'size_bytes' => 16]);
    Document::factory()->for($this->user)->create(['filename' => 'notes.docx']);

    $export = runExport(DataExport::factory()->for($this->user)->create());
    $entries = exportEntries($export);

    expect($export->status)->toBe(DataExportStatus::Completed)
        ->and(array_keys($entries))->toEqualCanonicalizing([...array_map(fn (string $name): string => $name.'.csv', EXPORT_TYPES), 'all.ndjson', 'manifest.json'])
        ->and($entries['all.ndjson'])->toContain('"type":"transactions"')
        ->and($entries['manifest.json'])->toContain('receipt.pdf')->toContain(hash('sha256', 'attachment bytes'))->toContain('notes.docx')
        ->and(implode('', $entries))->not->toContain('attachment bytes');
});

it('exports split parts instead of the parent', function () {
    $parent = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -10000, 'description' => 'Split purchase']);
    TransactionSplit::query()->create(['transaction_id' => $parent->id, 'amount' => -6000, 'position' => 1]);
    TransactionSplit::query()->create(['transaction_id' => $parent->id, 'amount' => -4000, 'position' => 2]);

    $csv = exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'];

    expect($csv)->toContain('-60.00')->toContain('-40.00')->not->toContain('-100.00');
});

it('exports only the requesting user\'s data', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'description' => 'Mine']);
    $other = User::factory()->create();
    Transaction::factory()->for($other)->create(['account_id' => Account::factory()->for($other)->create()->id, 'description' => 'Theirs']);

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect(implode('', $entries))->toContain('Mine')->not->toContain('Theirs');
});

it('never restarts a completed or failed export', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    $path = $export->stored_path;
    $size = $export->byte_size;
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'description' => 'Added later']);

    $again = runExport($export);

    expect($again->status)->toBe(DataExportStatus::Completed)
        ->and($again->byte_size)->toBe($size)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $failed = DataExport::factory()->for($this->user)->create(['status' => DataExportStatus::Failed]);
    expect(runExport($failed)->status)->toBe(DataExportStatus::Failed);
});

it('fails exports stuck for over two hours', function () {
    $stuck = DataExport::factory()->for($this->user)->processingSince(now()->subHours(3))->create();
    $fresh = DataExport::factory()->for($this->user)->processingSince(now()->subHour())->create();

    expect(DataExport::failStuck())->toBe(1)
        ->and($stuck->fresh()->status)->toBe(DataExportStatus::Failed)
        ->and($fresh->fresh()->status)->toBe(DataExportStatus::Processing);
});

it('writes amounts as decimal strings from integer cents', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -1205]);

    expect(exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'])->toContain('-12.05');
});

it('marks the export failed when the archive cannot be written', function () {
    $export = DataExport::factory()->for($this->user)->create();
    Storage::shouldReceive('disk')->with('local')->andThrow(new RuntimeException('disk full'));

    $done = runExport($export);

    expect($done->status)->toBe(DataExportStatus::Failed)->and($done->failure_message)->not->toContain('disk full');
});

it('escapes text cells that could run as a formula but keeps negative amounts', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -1205, 'description' => '=HYPERLINK("http://evil.example","x")']);

    $csv = exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'];

    expect($csv)->not->toContain(',"=HYPERLINK')->toContain("'=HYPERLINK")->toContain('-12.05');
});

it('exports the currency, notes, payees and rule definitions', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'currency' => 'USD', 'notes' => 'my note']);
    App\Models\Payee::factory()->for($this->user)->create(['merchant_name' => 'PAYEE-ZZZ']);
    App\Models\UserRule::factory()->for($this->user)->create(['triggers' => [['value' => 'NETFLIX']]]);

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect($entries['transactions.csv'])->toContain('USD')->toContain('my note')
        ->and($entries['payees.csv'])->toContain('PAYEE-ZZZ')
        ->and($entries['rules.csv'])->toContain('NETFLIX');
});

it('never exports credentials, tokens or vector store ids', function () {
    $user = User::factory()->create(['vector_store_id' => 'vs_secret_id']);
    App\Models\GmailCredential::factory()->for($user)->create(['app_password' => 'gmail-app-secret']);
    App\Models\RedbarkFeed::factory()->for($user)->create(['api_key' => 'rbk_live_secret_key']);

    $all = implode('', exportEntries(runExport(DataExport::factory()->for($user)->create())));

    expect($all)->not->toContain('vs_secret_id')->not->toContain('gmail-app-secret')->not->toContain('rbk_live_secret_key')->not->toContain($user->password)->not->toContain((string) $user->remember_token);
});

it('marks the export failed when the archive cannot be stored', function () {
    $export = DataExport::factory()->for($this->user)->create();
    Storage::shouldReceive('disk')->with('local')->andReturn(new class
    {
        public function writeStream(): bool
        {
            return false;
        }
    });

    expect(runExport($export)->status)->toBe(DataExportStatus::Failed);
});

it('removes the stored archive when the export is deleted', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    $path = $export->stored_path;

    $export->delete();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('removes archives and documents when a user is deleted', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    Storage::disk('local')->put('documents/d.docx', 'x');
    Document::factory()->for($this->user)->create(['stored_path' => 'documents/d.docx']);

    $this->user->delete();

    expect(Storage::disk('local')->exists($export->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->exists('documents/d.docx'))->toBeFalse();
});

function seedEveryKind(User $user, string $label): void
{
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->create(['account_id' => $account->id]);

    $goal = Goal::factory()->for($user)->create(['name' => "GOAL-{$label}", 'target_amount' => 1234567]);
    GoalAccount::factory()->create(['goal_id' => $goal->id, 'account_id' => $account->id, 'earmark_amount' => 250050]);
    GoalPledge::factory()->create(['goal_id' => $goal->id, 'account_id' => $account->id, 'amount' => 80075]);
    GoalSpend::factory()->create(['goal_id' => $goal->id, 'account_id' => $account->id, 'transaction_id' => $transaction->id, 'amount' => 40025]);

    $plan = BudgetPlan::factory()->for($user)->create(['expected_income' => 765432, 'total_amount' => 300000]);
    Budget::factory()->for($user)->create(['budget_plan_id' => $plan->id, 'name' => "BUDGET-{$label}", 'limit_amount' => 99905]);

    $planned = PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id, 'description' => "PLANNED-{$label}", 'amount' => 15099]);
    PlannedOccurrence::factory()->create(['planned_transaction_id' => $planned->id, 'original_date' => '2031-01-15', 'due_date' => '2031-01-15', 'expected_amount' => 15101]);

    $tag = Tag::factory()->for($user)->create(['name' => "TAG-{$label}"]);
    $transaction->tags()->attach($tag->id);

    TransferRule::query()->create(['user_id' => $user->id, 'account_id' => $account->id, 'counterpart_account_id' => Account::factory()->for($user)->create()->id, 'description_pattern' => "TRANSFER-{$label}"]);

    $order = BnplOrder::factory()->for($user)->create(['retailer' => "RETAILER-{$label}", 'total' => 7445, 'gmail_message_id' => "gmail-id-{$label}", 'gmail_url' => "https://mail.example/{$label}", 'snippet' => "snippet-{$label}", 'parsed_payload' => ['raw' => "payload-{$label}"]]);
    BnplOrderEvent::factory()->create(['bnpl_order_id' => $order->id, 'actor' => "ACTOR-{$label}"]);

    Statement::factory()->for($user)->create(['original_filename' => "STATEMENT-{$label}.pdf", 'stored_path' => "statements/path-{$label}.pdf", 'opening_balance' => 100000, 'closing_balance' => -2550]);

    $merchant = Merchant::factory()->for($user)->create();
    MerchantAlias::factory()->create(['merchant_id' => $merchant->id, 'user_id' => $user->id, 'merchant_key' => "ALIAS-{$label}"]);
}

it('writes a csv and ndjson records for every record type', function (string $type, string $marker) {
    seedEveryKind($this->user, 'MINE');

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect($entries[$type.'.csv'])->toContain($marker)
        ->and($entries['all.ndjson'])->toContain('"type":"'.$type.'"');
})->with([
    'goals' => ['goals', 'GOAL-MINE'],
    'goal accounts' => ['goal_accounts', '2500.50'],
    'goal pledges' => ['goal_pledges', '800.75'],
    'goal spends' => ['goal_spends', '400.25'],
    'budget plans' => ['budget_plans', '7654.32'],
    'budgets' => ['budgets', 'BUDGET-MINE'],
    'planned transactions' => ['planned_transactions', 'PLANNED-MINE'],
    'planned occurrences' => ['planned_occurrences', '151.01'],
    'tags' => ['tags', 'TAG-MINE'],
    'tag links' => ['tag_transaction', 'tag_id'],
    'transfer rules' => ['transfer_rules', 'TRANSFER-MINE'],
    'bnpl orders' => ['bnpl_orders', 'RETAILER-MINE'],
    'bnpl order events' => ['bnpl_order_events', 'ACTOR-MINE'],
    'statements' => ['statements', 'STATEMENT-MINE.pdf'],
    'merchant aliases' => ['merchant_aliases', 'ALIAS-MINE'],
]);

it('exports none of another user\'s goals, budgets, bills, tags, transfer rules, bnpl orders, statements or aliases', function () {
    seedEveryKind($this->user, 'MINE');
    seedEveryKind(User::factory()->create(), 'THEIRS');

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect(implode('', $entries))->toContain('GOAL-MINE')->not->toContain('THEIRS');

    foreach (NEW_EXPORT_TYPES as $type) {
        $own = mb_substr_count($entries[$type.'.csv'], "\n");
        expect($own)->toBeGreaterThanOrEqual(2, $type.' has its own record');
    }

    $ids = fn (string $type): array => array_map(fn (array $row): string => $row[0], array_slice(array_map('str_getcsv', explode("\n", mb_trim($entries[$type.'.csv']))), 1));

    expect($ids('goals'))->toHaveCount(1)
        ->and($ids('tag_transaction'))->toHaveCount(1)
        ->and($ids('statements'))->toHaveCount(1);
});

it('never exports a stored file path, Gmail message ids or links, or the email body of the new record types', function () {
    seedEveryKind($this->user, 'MINE');

    $all = implode('', exportEntries(runExport(DataExport::factory()->for($this->user)->create())));

    expect($all)->not->toContain('statements/path-MINE.pdf')
        ->not->toContain('gmail-id-MINE')
        ->not->toContain('https://mail.example/MINE')
        ->not->toContain('snippet-MINE')
        ->not->toContain('payload-MINE')
        ->not->toContain('stored_path')
        ->not->toContain('gmail_message_id');
});

it('leaves the gmail message ids out of a bnpl event payload and keeps its other keys', function () {
    $order = BnplOrder::factory()->for($this->user)->create();
    BnplOrderEvent::factory()->create(['bnpl_order_id' => $order->id, 'payload' => ['message_id' => 'gmail-pulled-id']]);
    BnplOrderEvent::factory()->create(['bnpl_order_id' => $order->id, 'payload' => ['gmail_message_id' => 'gmail-linked-id', 'amount' => 1861, 'posted_on' => '2026-03-01']]);

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect(implode('', $entries))->not->toContain('gmail-pulled-id')->not->toContain('gmail-linked-id')->not->toContain('message_id')
        ->and($entries['bnpl_order_events.csv'])->toContain('1861')->toContain('2026-03-01');
});

it('writes the amounts of the new record types as decimal strings from integer cents', function () {
    seedEveryKind($this->user, 'MINE');

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect($entries['goals.csv'])->toContain('12345.67')
        ->and($entries['budgets.csv'])->toContain('999.05')
        ->and($entries['planned_transactions.csv'])->toContain('150.99')
        ->and($entries['bnpl_orders.csv'])->toContain('74.45')
        ->and($entries['statements.csv'])->toContain('1000.00')->toContain('-25.50')
        ->and($entries['statements.csv'])->not->toContain('100000')->not->toContain('-2550');
});

it('reads the child and link records in chunks without a query per row', function () {
    $queries = function (int $count): int {
        $user = User::factory()->create();

        foreach (range(1, $count) as $i) {
            seedEveryKind($user, "N{$i}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        runExport(DataExport::factory()->for($user)->create());
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    $few = $queries(1);
    $many = $queries(4);

    expect($many - $few)->toBeLessThanOrEqual(3);
});
