<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The rules for a tag's name and colour, shared by the REST API and the assistant: the name is trimmed and unique per
 * user, the colour is a #RRGGBB hex.
 */
final class TagInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function validate(array $input, int $userId, ?int $ignoreId, bool $creating): array
    {
        $input = array_intersect_key($input, ['name' => true, 'colour' => true]);

        if (is_string($input['name'] ?? null)) {
            $input['name'] = mb_trim($input['name']);
        }

        $presence = $creating ? 'required' : 'sometimes';

        /** @var array<string, string> $validated */
        $validated = Validator::make($input, [
            'name' => [
                $presence,
                'string',
                'max:50',
                Rule::notIn([Tag::RESERVED_NAME]),
                Rule::unique(Tag::class, 'name')->where('user_id', $userId)->ignore($ignoreId),
            ],
            'colour' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}\z/'],
        ])->validate();

        return $validated;
    }
}
