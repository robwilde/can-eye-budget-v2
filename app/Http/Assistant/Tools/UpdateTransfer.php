<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Enums\TransferLinkSource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\TransferReviewQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

final class UpdateTransfer implements AssistantWriteTool
{
    use WritesUserData;

    private const array ACTIONS = ['confirm', 'link', 'not_a_transfer'];

    public function __construct(
        private readonly TransferLinker $linker,
        private readonly TransferReviewQueue $queue,
    ) {}

    public function name(): string
    {
        return 'update_transfer';
    }

    public function description(): string
    {
        return 'Settles a transfer between two of the user\'s transactions: "confirm" accepts the suggested partner, "link" pairs the transaction with the given partner_id, "not_a_transfer" rejects the suggestion. Returns whether anything changed.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'transaction_id' => ['type' => 'integer'],
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'partner_id' => ['type' => 'integer', 'description' => 'The other leg; only for "link".'],
            ],
            'required' => ['transaction_id', 'action'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'transaction_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(self::ACTIONS)],
            'partner_id' => ['required_if:action,link', 'integer', 'min:1', 'different:transaction_id'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        return $this->attempt(function () use ($user, $data): array {
            $transaction = $this->owned($user, (int) $data['transaction_id']);

            $changed = match ($data['action']) {
                'confirm' => $this->linker->confirm($transaction),
                'not_a_transfer' => $this->linker->markNotTransfer($this->queued($user, $transaction->id)),
                default => $this->linker->link($transaction, $this->candidate($transaction, (int) $data['partner_id']), TransferLinkSource::Manual),
            };

            if ($changed) {
                $this->audited($user, 'transaction', $transaction->id);
            }

            return ['changed' => $changed, 'transaction_id' => $transaction->id];
        });
    }

    /**
     * The row as the transfer review offers it: a pending suggestion or an unmatched bank row.
     */
    private function queued(User $user, int $id): Transaction
    {
        $suggested = Transaction::query()->where('user_id', $user->id)->current()->possibleTransfer()->find($id);

        return $suggested instanceof Transaction ? $suggested : $this->queue->unmatched($user)->findOrFail($id);
    }

    /**
     * Only a row the transactions page would offer as a partner can be linked.
     */
    private function candidate(Transaction $transaction, int $partnerId): Transaction
    {
        $candidates = $this->linker->candidatesFor($transaction, includeRejected: true, windowDays: TransferLinker::MANUAL_WINDOW_DAYS, crossCurrency: true);

        return $candidates->firstWhere('id', $partnerId) ?? throw (new ModelNotFoundException)->setModel(Transaction::class, [$partnerId]);
    }

    private function owned(User $user, int $id): Transaction
    {
        return Transaction::query()->where('user_id', $user->id)->current()->findOrFail($id);
    }
}
