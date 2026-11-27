<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Category;
use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use Illuminate\Validation\Rule;

final class UpdateTransaction implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly TransactionWriter $writer) {}

    public function name(): string
    {
        return 'update_transaction';
    }

    public function description(): string
    {
        return 'Changes the category, notes or description of one of the user\'s transactions. A description can only change on a manually entered transaction. Set clear_category or clear_notes to true to remove the category or the notes. A change creates a new version of the transaction with a NEW id: use the id in the result from then on.';
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
                'category_id' => ['type' => 'integer'],
                'notes' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'clear_category' => ['type' => 'boolean'],
                'clear_notes' => ['type' => 'boolean'],
            ],
            'required' => ['transaction_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'transaction_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'notes' => ['sometimes', 'string', 'max:1000'],
            'description' => ['sometimes', 'string', 'max:255'],
            'clear_category' => ['sometimes', 'boolean'],
            'clear_notes' => ['sometimes', 'boolean'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        $changes = array_intersect_key($data, ['category_id' => true, 'notes' => true, 'description' => true]);

        if (($data['clear_category'] ?? false) === true) {
            $changes['category_id'] = null;
        }

        if (($data['clear_notes'] ?? false) === true) {
            $changes['notes'] = null;
        }

        if ($changes === []) {
            return ['error' => 'Nothing to change: give a category_id, notes, description, clear_category or clear_notes.'];
        }

        return $this->attempt(function () use ($user, $data, $changes): array {
            $transaction = $this->writer->update($user, (int) $data['transaction_id'], $changes);
            $this->audited($user, 'transaction', $transaction->id);

            return ['data' => $this->present([$transaction->load('splits')], TransactionResource::class)[0]];
        });
    }
}
