<?php

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoalManager;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{user: int, goal: int, amount: int, account: int|null, transaction: int|null} $job */
$job = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

try {
    $user = User::query()->findOrFail($job['user']);
    $goal = Goal::query()->findOrFail($job['goal']);
    $account = $job['account'] === null ? null : Account::query()->find($job['account']);
    $transaction = $job['transaction'] === null ? null : Transaction::query()->where('direction', TransactionDirection::Debit)->find($job['transaction']);

    $app->make(GoalManager::class)->recordSpend($user, $goal, $job['amount'], $account, $transaction);

    echo json_encode(['outcome' => 'spent']);
} catch (GoalRefusedException $exception) {
    echo json_encode(['outcome' => 'refused', 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()]);
}
