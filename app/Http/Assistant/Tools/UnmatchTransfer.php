<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transfers\TransferLinker;

final class UnmatchTransfer implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly TransferLinker $linker) {}

    public function name(): string
    {
        return 'unmatch_transfer';
    }

    public function description(): string
    {
        return 'Unlinks a linked transfer from its partner. Imported rows are kept; a mirror leg that was created on an untracked account only for the link is removed with it.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['transaction_id' => ['type' => 'integer']],
            'required' => ['transaction_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, ['transaction_id' => ['required', 'integer', 'min:1']]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        return $this->attempt(function () use ($user, $data): array {
            $transaction = Transaction::query()->where('user_id', $user->id)->current()->findOrFail((int) $data['transaction_id']);

            if ($transaction->transfer_pair_id === null) {
                return ['error' => 'This transaction is not linked to a transfer.'];
            }

            $this->linker->unlink($transaction);
            $this->audited($user, 'transaction', $transaction->id);

            return ['unlinked' => true, 'transaction_id' => $transaction->id];
        });
    }
}
