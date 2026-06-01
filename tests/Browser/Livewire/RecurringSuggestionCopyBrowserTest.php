<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;

test('clicking a recurring suggestion opens the prefilled transaction modal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $pipelineRun = PipelineRun::factory()->for($user)->create();

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Direct Debit Spaceship',
        'post_date' => '2026-04-15',
    ]);

    AnalysisSuggestion::factory()->recurringTransaction()->create([
        'pipeline_run_id' => $pipelineRun->id,
        'user_id' => $user->id,
        'payload' => [
            'description' => 'SPACESHIP',
            'clean_description' => 'Spaceship Voyager',
            'amount' => 20000,
            'direction' => 'debit',
            'frequency' => 'every-month',
            'account_id' => $account->id,
            'category_id' => null,
            'matched_transaction_ids' => [$transaction->id],
            'start_date' => '2026-04-15',
            'confidence_score' => 0.9,
        ],
    ]);

    $this->actingAs($user);

    $page = visit('/connect-bank');

    // The suggestion renders and is clickable; the modal is not yet open.
    $page->assertSee('Recurring Transactions Detected')
        ->assertSee('Spaceship Voyager')
        ->assertDontSee('Add transaction')
        ->click('.tx-row-hit')
        ->assertSee('Add transaction');
});
