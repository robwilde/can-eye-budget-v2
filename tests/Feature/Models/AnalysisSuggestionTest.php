<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\User;

test('factory creates a valid analysis suggestion', function () {
    $suggestion = AnalysisSuggestion::factory()->create();

    expect($suggestion)->toBeInstanceOf(AnalysisSuggestion::class)
        ->and($suggestion->exists)->toBeTrue();
});

test('default factory creates a pending primary account suggestion', function () {
    $suggestion = AnalysisSuggestion::factory()->create();

    expect($suggestion->type)->toBe(SuggestionType::PrimaryAccount)
        ->and($suggestion->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->payload)->toBe([]);
});

test('default factory derives user from pipeline run', function () {
    $suggestion = AnalysisSuggestion::factory()->create();

    expect($suggestion->user_id)->toBe($suggestion->pipelineRun->user_id);
});

test('accepted state sets accepted status with resolved_at', function () {
    $suggestion = AnalysisSuggestion::factory()->accepted()->create();

    expect($suggestion->status)->toBe(SuggestionStatus::Accepted)
        ->and($suggestion->resolved_at)->not->toBeNull();
});

test('rejected state sets rejected status with resolved_at', function () {
    $suggestion = AnalysisSuggestion::factory()->rejected()->create();

    expect($suggestion->status)->toBe(SuggestionStatus::Rejected)
        ->and($suggestion->resolved_at)->not->toBeNull();
});

test('superseded state sets superseded status with resolved_at', function () {
    $suggestion = AnalysisSuggestion::factory()->superseded()->create();

    expect($suggestion->status)->toBe(SuggestionStatus::Superseded)
        ->and($suggestion->resolved_at)->not->toBeNull();
});

test('pay cycle state sets pay cycle type', function () {
    $suggestion = AnalysisSuggestion::factory()->payCycle()->create();

    expect($suggestion->type)->toBe(SuggestionType::PayCycle);
});

test('recurring transaction state sets recurring transaction type', function () {
    $suggestion = AnalysisSuggestion::factory()->recurringTransaction()->create();

    expect($suggestion->type)->toBe(SuggestionType::RecurringTransaction);
});

test('belongs to a user', function () {
    $user = User::factory()->create();
    $suggestion = AnalysisSuggestion::factory()->create(['user_id' => $user->id]);

    expect($suggestion->user->id)->toBe($user->id);
});

test('belongs to a pipeline run', function () {
    $run = PipelineRun::factory()->create();
    $suggestion = AnalysisSuggestion::factory()->for($run)->create([
        'user_id' => $run->user_id,
    ]);

    expect($suggestion->pipelineRun->id)->toBe($run->id);
});

test('pending scope filters pending suggestions', function () {
    $user = User::factory()->create();
    $run = PipelineRun::factory()->for($user)->create();

    AnalysisSuggestion::factory()->for($run)->create([
        'user_id' => $user->id,
        'status' => SuggestionStatus::Pending,
    ]);
    AnalysisSuggestion::factory()->accepted()->for($run)->create([
        'user_id' => $user->id,
    ]);

    expect(AnalysisSuggestion::query()->pending()->count())->toBe(1);
});

test('ofType scope filters by suggestion type', function () {
    $user = User::factory()->create();
    $run = PipelineRun::factory()->for($user)->create();

    AnalysisSuggestion::factory()->for($run)->create([
        'user_id' => $user->id,
        'type' => SuggestionType::PrimaryAccount,
    ]);
    AnalysisSuggestion::factory()->payCycle()->for($run)->create([
        'user_id' => $user->id,
    ]);

    expect(AnalysisSuggestion::query()->ofType(SuggestionType::PayCycle)->count())->toBe(1);
});

test('type is cast to SuggestionType enum', function () {
    $suggestion = AnalysisSuggestion::factory()->create();

    expect($suggestion->type)->toBeInstanceOf(SuggestionType::class);
});

test('status is cast to SuggestionStatus enum', function () {
    $suggestion = AnalysisSuggestion::factory()->create();

    expect($suggestion->status)->toBeInstanceOf(SuggestionStatus::class);
});

test('payload is cast to array', function () {
    $payload = ['account_id' => 1, 'confidence' => 0.95];
    $suggestion = AnalysisSuggestion::factory()->create(['payload' => $payload]);

    expect($suggestion->payload)->toBe($payload);
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    $run = PipelineRun::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($run)->create(['user_id' => $user->id]);

    $user->delete();

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});

test('cascades on pipeline run delete', function () {
    $run = PipelineRun::factory()->create();
    AnalysisSuggestion::factory()->for($run)->create(['user_id' => $run->user_id]);

    $run->delete();

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});
