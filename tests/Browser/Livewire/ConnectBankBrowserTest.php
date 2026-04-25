<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\User;

test('connect-bank suggestion accept and dismiss buttons are visible', function () {
    $user = User::factory()->create(['basiq_user_id' => 'basiq-user-test']);
    $account = Account::factory()->for($user)->create(['name' => 'Everyday Account']);
    $pipelineRun = PipelineRun::factory()->for($user)->create();

    AnalysisSuggestion::factory()->primaryAccount()->create([
        'pipeline_run_id' => $pipelineRun->id,
        'user_id' => $user->id,
        'payload' => [
            'account_id' => $account->id,
            'account_name' => 'Everyday Account',
            'income_amount' => 300000,
            'income_frequency' => 'fortnightly',
            'income_description' => 'EMPLOYER PTY LTD',
            'confidence_score' => 0.85,
            'matched_transaction_ids' => [],
            'outbound_transfer_count' => 3,
        ],
    ]);

    $this->actingAs($user);

    $page = visit('/connect-bank');

    $page->assertSee('Primary Account Detected')
        ->assertSee('Accept')
        ->assertSee('Dismiss')
        ->assertNoJavaScriptErrors();
});
