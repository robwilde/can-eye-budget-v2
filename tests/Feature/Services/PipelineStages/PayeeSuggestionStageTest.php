<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PipelineTrigger;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\PayeeSuggestionStage;
use App\Services\TransactionAnalysisPipeline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.typesafe.api_key' => 'ts_test', 'budget.jev_categorisation' => true]);
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->create(['name' => 'Groceries', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
    $this->transport = Category::factory()->create(['name' => 'Transport', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
});

function stageRow(User $user, Account $account, string $merchant): Transaction
{
    return Transaction::factory()->for($user)->for($account)->debit()->create([
        'description' => 'CARD '.$merchant.'  REF 1',
        'merchant_name' => $merchant,
        'category_id' => null,
        'category_source' => null,
    ]);
}

function stageContext(User $user): PipelineContext
{
    return new PipelineContext(
        user: $user,
        pipelineRun: PipelineRun::factory()->create(['user_id' => $user->id]),
        isFirstSync: false,
    );
}

function stageJevAnswers(string $path): void
{
    Http::fake(function (Request $request) use ($path) {
        $criteria = $request['questions']['answer']['criteria'];
        $choice = array_search($path, $criteria, true);
        $others = max(1, count($criteria) - 1);
        $probabilities = array_map(fn () => 0.05 / $others, $criteria);
        $probabilities[$choice] = 0.95;

        return Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['answer' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => $probabilities, 'confidence' => 0.95]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 3],
        ]);
    });
}

test('it has a stable key and label', function () {
    $stage = app(PayeeSuggestionStage::class);

    expect($stage->key())->toBe('payee-suggestion')
        ->and($stage->label())->toBe('Payee Suggestions');
});

test('it does not run when the Jev categorisation flag is off even with a key configured', function () {
    config(['budget.jev_categorisation' => false]);
    stageRow($this->user, $this->account, 'ALDI 123');

    expect(app(PayeeSuggestionStage::class)->shouldRun(stageContext($this->user)))->toBeFalse();
});

test('it does not run without a TypeSafe key, and building it does not need one', function () {
    config(['services.typesafe.api_key' => null]);
    stageRow($this->user, $this->account, 'ALDI 123');

    expect(app(PayeeSuggestionStage::class)->shouldRun(stageContext($this->user)))->toBeFalse();
});

test('it runs only when the user has uncategorised merchant debits nobody has asked about', function () {
    $stage = app(PayeeSuggestionStage::class);

    expect($stage->shouldRun(stageContext($this->user)))->toBeFalse();

    $row = stageRow($this->user, $this->account, 'ALDI 123');

    expect($stage->shouldRun(stageContext($this->user)))->toBeTrue();

    Payee::factory()->create(['user_id' => $this->user->id, 'merchant_key' => $row->merchant_key]);

    expect($stage->shouldRun(stageContext($this->user)))->toBeFalse();
});

test("it ignores another user's uncategorised debits", function () {
    $other = User::factory()->create();
    stageRow($other, Account::factory()->for($other)->create(), 'ALDI 123');

    expect(app(PayeeSuggestionStage::class)->shouldRun(stageContext($this->user)))->toBeFalse();
});

test('it suggests, applies the confident answer and audits the counts', function () {
    stageJevAnswers('Groceries');
    $row = stageRow($this->user, $this->account, 'ALDI 123');
    $context = stageContext($this->user);

    $result = app(PayeeSuggestionStage::class)->execute($context);

    $entry = PipelineAuditEntry::query()->where('pipeline_run_id', $context->pipelineRun->id)->sole();

    expect($result->success)->toBeTrue()
        ->and($row->fresh()->category_id)->toBe($this->groceries->id)
        ->and($row->fresh()->category_source)->toBe(CategorySource::Suggested)
        ->and($entry->stage)->toBe('payee-suggestion')
        ->and($entry->action)->toBe('payees_suggested')
        ->and($entry->metadata)->toBe(['suggested' => 1, 'auto_applied' => 1]);
});

test('a Jev failure is recorded as skipped and does not fail the stage', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $row = stageRow($this->user, $this->account, 'ALDI 123');
    $context = stageContext($this->user);

    $result = app(PayeeSuggestionStage::class)->execute($context);

    $entry = PipelineAuditEntry::query()->where('pipeline_run_id', $context->pipelineRun->id)->sole();

    expect($result->success)->toBeTrue()
        ->and($entry->action)->toBe('skipped')
        ->and($entry->metadata['reason'])->toBe('typesafe_error')
        ->and($entry->metadata['exception'])->toBe(App\Exceptions\TypeSafe\TypeSafeException::class)
        ->and($row->fresh()->category_id)->toBeNull()
        ->and(Payee::query()->count())->toBe(0);
});

test('payees written before a Jev failure are kept and counted', function () {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        if ($calls > 1) {
            return Http::response([], 500);
        }

        $criteria = $request['questions']['answer']['criteria'];
        $choice = array_key_first($criteria);

        return Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['answer' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => [$choice => 0.5, array_keys($criteria)[1] => 0.5], 'confidence' => 0.5]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 3],
        ]);
    });
    stageRow($this->user, $this->account, 'AAA SHOP');
    stageRow($this->user, $this->account, 'ZZZ SHOP');
    $context = stageContext($this->user);

    app(PayeeSuggestionStage::class)->execute($context);

    $entry = PipelineAuditEntry::query()->where('pipeline_run_id', $context->pipelineRun->id)->sole();

    expect(Payee::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($entry->metadata['suggested'])->toBe(1);
});

test('in the real pipeline a Jev outage leaves the run unfailed and the stage completed', function () {
    Http::fake(['*' => Http::response([], 500)]);
    stageRow($this->user, $this->account, 'ALDI 123');

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('payee-suggestion')
        ->and(collect($run->stages_failed)->pluck('stage')->all())->not->toContain('payee-suggestion');
});

test('in the real pipeline the stage is skipped without a TypeSafe key', function () {
    config(['services.typesafe.api_key' => null]);
    Http::fake();
    stageRow($this->user, $this->account, 'ALDI 123');

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_skipped)->toContain('payee-suggestion');
    Http::assertNothingSent();
});
