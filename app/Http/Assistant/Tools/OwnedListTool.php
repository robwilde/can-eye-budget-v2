<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantTool;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A paginated list of one kind of the user's own records, in the same shape and order as the REST API.
 */
abstract class OwnedListTool implements AssistantTool
{
    use ReadsUserData;

    public const int PAGE_SIZE = 50;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * @return class-string<JsonResource>
     */
    abstract protected function resource(): string;

    /**
     * @return list<string>
     */
    abstract protected function order(): array;

    final public function requiresPreview(): bool
    {
        return false;
    }

    final public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, 50 rows per page. Defaults to 1.']],
        ];
    }

    final public function handle(User $user, array $arguments): array
    {
        $valid = $this->validated($arguments, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000']]);

        if (is_string($valid)) {
            return ['error' => $valid];
        }

        $page = (int) ($valid['page'] ?? 1);

        /** @var Builder<Model> $query */
        $query = ($this->model())::query()->where('user_id', $user->id)->with($this->with());

        foreach ($this->order() as $column) {
            $query->orderBy($column);
        }

        $rows = $query->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return [
            'page' => $page,
            'has_more' => $rows->count() > self::PAGE_SIZE,
            'data' => $this->present($rows->take(self::PAGE_SIZE), $this->resource()),
        ];
    }

    /**
     * @return list<string>
     */
    protected function with(): array
    {
        return [];
    }
}
