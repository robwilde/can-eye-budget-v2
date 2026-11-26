<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagInput;

final class CreateTag implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly TagInput $input) {}

    public function name(): string
    {
        return 'create_tag';
    }

    public function description(): string
    {
        return 'Creates a tag with a unique name; the colour is a #RRGGBB hex and is picked when omitted.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'colour' => ['type' => 'string', 'description' => '#RRGGBB']],
            'required' => ['name'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        return $this->attempt(function () use ($user, $arguments): array {
            $validated = $this->input->validate($this->withoutBlanks($arguments), $user->id, null, true);
            $tag = Tag::query()->create(['user_id' => $user->id, ...$validated]);
            $this->audited($user, 'tag', $tag->id);

            return ['data' => $this->present([$tag], TagResource::class)[0]];
        });
    }
}
