<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Models\User;
use App\Services\Transactions\ApiTransactionWriter;

final class DeleteTransaction implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly ApiTransactionWriter $writer) {}

    public function name(): string
    {
        return 'delete_transaction';
    }

    public function description(): string
    {
        return 'Deletes one whole manually entered transaction by its transaction id. Imported bank transactions and the lines of a split are kept and refused.';
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
            $this->writer->delete($user, (int) $data['transaction_id']);
            $this->audited($user, 'transaction', (int) $data['transaction_id']);

            return ['deleted' => true, 'transaction_id' => (int) $data['transaction_id']];
        });
    }
}
