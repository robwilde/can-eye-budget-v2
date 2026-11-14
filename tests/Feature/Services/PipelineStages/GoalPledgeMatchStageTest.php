<?php

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\GoalPledgeStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalPledge;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\GoalPledgeMatchStage;
use App\Services\TransactionAnalysisPipeline;

function pledgeContext(User $user): PipelineContext
{
    return new PipelineContext(user: $user, pipelineRun: PipelineRun::factory()->for($user)->create(), isFirstSync: false);
}

it('runs only while goals are enabled', function () {
    $context = pledgeContext(User::factory()->create());
    $stage = app(GoalPledgeMatchStage::class);

    config(['budget.goals_enabled' => false]);
    expect($stage->shouldRun($context))->toBeFalse();

    config(['budget.goals_enabled' => true]);
    expect($stage->shouldRun($context))->toBeTrue();
});

it('matches open pledges when executed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $pledge = GoalPledge::factory()->for(Goal::factory()->for($user))->for($account)->create([
        'amount' => 20000,
        'created_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);
    Transaction::factory()->for($user)->for($account)->credit()->create(['amount' => 20000, 'post_date' => now()->addDay()]);

    $result = app(GoalPledgeMatchStage::class)->execute(pledgeContext($user));

    expect($result->success)->toBeTrue()
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Matched);
});

it('is registered after planned transaction matching in the analysis pipeline', function () {
    $stages = (new ReflectionProperty(TransactionAnalysisPipeline::class, 'stages'))->getValue(app(TransactionAnalysisPipeline::class));
    $keys = array_map(fn ($stage): string => $stage->key(), $stages);

    expect(array_slice($keys, -2))->toBe(['match-planned-transactions', 'match-goal-pledges']);
});
