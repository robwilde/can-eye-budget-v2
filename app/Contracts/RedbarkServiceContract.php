<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\RedbarkAccountData;
use App\DTOs\RedbarkBalanceData;
use App\DTOs\RedbarkConnectionData;
use App\DTOs\RedbarkTransactionData;
use App\Exceptions\Redbark\RedbarkException;
use Carbon\CarbonImmutable;

interface RedbarkServiceContract
{
    /**
     * @return list<RedbarkAccountData>
     *
     * @throws RedbarkException
     */
    public function listAccounts(): array;

    /**
     * @return list<RedbarkConnectionData>
     *
     * @throws RedbarkException
     */
    public function listConnections(): array;

    /**
     * @param  list<string>  $accountIds
     * @return list<RedbarkBalanceData>
     *
     * @throws RedbarkException
     */
    public function getBalances(array $accountIds): array;

    /**
     * @return list<RedbarkTransactionData>
     *
     * @throws RedbarkException
     */
    public function getTransactions(
        string $connectionId,
        string $accountId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includePending = false,
    ): array;
}
