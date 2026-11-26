<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagInput;

final class UpdateTag implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly TagInput $input) {}

    public function name(): string
    {
        return 'update_tag';
    }

    public function description(): string
    {
        return 'Renames or recolours one of the user\'s tags.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['tag_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'colour' => ['type' => 'string', 'description' => '#RRGGBB']],
            'required' => ['tag_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        return $this->attempt(function () use ($user, $arguments): array {
            $arguments = $this->withoutBlanks($arguments);
            $tag = Tag::query()->where('user_id', $user->id)->findOrFail((int) ($arguments['tag_id'] ?? 0));
            $validated = $this->input->validate($arguments, $user->id, $tag->id, false);

            if ($validated === []) {
                return ['error' => 'Nothing to change: give a name or colour.'];
            }

            $tag->update($validated);
            $this->audited($user, 'tag', $tag->id);

            return ['data' => $this->present([$tag], TagResource::class)[0]];
        });
    }
}
