<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EmailSearchResult;
use App\Models\Transaction;
use App\Models\TransactionEmail;

/**
 * The single write path for attaching an email to a transaction, used by the
 * manual "link email" action and the BNPL receipt linker. Attaching the same
 * message to the same transaction twice returns the existing row.
 */
final class TransactionEmailLinker
{
    public function link(Transaction $transaction, EmailSearchResult $result): TransactionEmail
    {
        return TransactionEmail::query()->firstOrCreate(
            [
                'transaction_id' => $transaction->id,
                'gmail_message_id' => $result->messageId,
            ],
            [
                'user_id' => $transaction->user_id,
                'subject' => $result->subject,
                'from_name' => $result->fromName,
                'from_address' => $result->fromAddress,
                'email_date' => $result->date,
                'snippet' => $result->snippet,
                'gmail_url' => $result->gmailUrl,
                'details' => $result->details,
            ],
        );
    }
}
