<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\EmailSearchResult;
use App\Exceptions\GmailSearchException;
use App\Models\Transaction;
use Illuminate\Support\Collection;

interface GmailServiceContract
{
    public function isConfigured(): bool;

    /**
     * Search Gmail for emails plausibly matching the transaction.
     *
     * @return Collection<int, EmailSearchResult> ranked by date proximity to post_date
     *
     * @throws GmailSearchException
     */
    public function searchForTransaction(Transaction $transaction): Collection;
}
